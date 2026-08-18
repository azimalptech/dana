<?php

declare(strict_types=1);

namespace Dana\Domain\Accounts;

use Dana\Domain\Auth\CredentialService;
use Dana\Domain\Models\Classroom;
use Dana\Domain\Models\User;
use Dana\Domain\Scope;
use Dana\Http\ApiException;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\QueryException;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * Creating student accounts, resetting passwords, and the audited
 * reveal (FR-1.10).
 *
 * A person attending two classrooms gets two accounts with distinct
 * logins (FR-1.17) — logins are unique system-wide, so at most one of
 * them can carry the person's real phone number.
 */
final class StudentAccountService
{
    /**
     * Reveals per actor per hour. A teacher legitimately reads back a
     * handful of forgotten passwords; a compromised staff account
     * harvesting the whole roster looks entirely different, and this cap
     * stops the harvest while never troubling the legitimate case.
     */
    private const MAX_REVEALS_PER_HOUR = 20;

    public function __construct(
        private readonly CredentialService $credentials,
        private readonly LoggerInterface $log,
    ) {
    }

    public function create(
        Scope $scope,
        Classroom $classroom,
        string $login,
        #[SensitiveParameter] string $password,
        string $fullName,
    ): User {
        $this->assertLoginShape($login);
        $this->assertPasswordShape($password);

        // A nameless student is a blank row in the register, the
        // leaderboard and their own profile, and nothing later asks for
        // the name again. Staff creation has always refused this.
        if (trim($fullName) === '') {
            throw ApiException::validation('Okuwçynyň adyny giriziň.', 'Введите имя ученика.');
        }

        if (User::query()->where('login', $login)->exists()) {
            // FR-1.17: this is the expected path when enrolling someone
            // who already studies at another classroom.
            throw ApiException::validation(
                "Bu belgi eýýäm ulanylýar: {$login}. Başga belgi giriziň.",
                "Этот номер уже используется: {$login}. Введите другой номер."
            );
        }

        $now = date('Y-m-d H:i:s');

        try {
            return Capsule::connection()->transaction(function () use (
                $scope, $classroom, $login, $password, $fullName, $now
            ): User {
                $student = new User();
                $student->role = User::ROLE_STUDENT;
                $student->center_id = $classroom->center_id;
                $student->classroom_id = $classroom->id;
                $student->login = $login;
                $student->password_hash = $this->credentials->hash($password);
                $student->full_name = $fullName;
                $student->is_active = true;
                $student->created_by = $scope->userId;
                $student->password_set_at = $now;
                $student->created_at = $now;
                $student->updated_at = $now;
                $student->save();

                // The reveal copy binds to the user id, so it can only be
                // decrypted in the row it belongs to. That means it has to be
                // written after the insert.
                $this->storeRecoverable($student, $password);

                $this->audit($scope, 'student.created', $student->id, ['classroom_id' => $classroom->id]);

                return $student;
            });
        } catch (QueryException $e) {
            // The exists() check above races: two teachers registering the
            // same number at once both pass it, and the loser lands here
            // on the unique key. Same answer as the check, not a 500.
            if (($e->errorInfo[0] ?? '') === '23000') {
                throw ApiException::validation(
                    "Bu belgi eýýäm ulanylýar: {$login}. Başga belgi giriziň.",
                    "Этот номер уже используется: {$login}. Введите другой номер."
                );
            }

            throw $e;
        }
    }

    public function resetPassword(Scope $scope, User $student, #[SensitiveParameter] string $password): void
    {
        $this->assertStudent($student);
        $this->assertPasswordShape($password);

        if (!$scope->mayRevealPasswordOf($student)) {
            throw ApiException::forbidden();
        }

        $student->password_hash = $this->credentials->hash($password);
        $student->password_set_at = date('Y-m-d H:i:s');
        $student->save();

        $this->storeRecoverable($student, $password);

        // All existing sessions die with the old password.
        Capsule::table('refresh_tokens')
            ->where('user_id', $student->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => date('Y-m-d H:i:s')]);

        $this->audit($scope, 'student.password_reset', $student->id);
        $this->log->info('student password reset', [
            'actor_id'   => $scope->userId,
            'student_id' => $student->id,
        ]);
    }

    /**
     * FR-1.10 + FR-1.12. The audit row is written BEFORE the value is
     * returned, so a failure to record the reveal is a failure to reveal.
     */
    public function reveal(Scope $scope, User $student, ?string $ip = null): string
    {
        $this->assertStudent($student);

        if (!$scope->mayRevealPasswordOf($student)) {
            throw ApiException::forbidden();
        }

        if ($student->password_ct === null) {
            throw ApiException::validation(
                'Bu hasabyň paroly görkezip bolmaýar. Täze parol belläň.',
                'Пароль этого аккаунта нельзя показать. Задайте новый пароль.'
            );
        }

        // The audit log doubles as the rate counter — reveals are already
        // recorded there (FR-1.12), and counting the same rows means the
        // throttle can never disagree with the trail.
        $recent = Capsule::table('audit_log')
            ->where('actor_id', $scope->userId)
            ->where('action', 'student.password_viewed')
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 3600))
            ->count();

        if ($recent >= self::MAX_REVEALS_PER_HOUR) {
            $this->log->warning('password reveal throttled', [
                'actor_id' => $scope->userId,
                'reveals'  => $recent,
            ]);
            throw ApiException::tooManyAttempts();
        }

        $this->audit($scope, 'student.password_viewed', $student->id, ['ip' => $ip]);

        $this->log->info('student password revealed', [
            'actor_id'   => $scope->userId,
            'actor_role' => $scope->role,
            'student_id' => $student->id,
            'ip'         => $ip,
        ]);

        return $this->credentials->decryptFor(
            (int) $student->id,
            $student->password_ct,
            $student->password_iv,
            $student->password_tag
        );
    }

    private function storeRecoverable(User $student, #[SensitiveParameter] string $password): void
    {
        $encrypted = $this->credentials->encryptFor((int) $student->id, $password);

        Capsule::table('users')->where('id', $student->id)->update([
            'password_ct'  => $encrypted['ct'],
            'password_iv'  => $encrypted['iv'],
            'password_tag' => $encrypted['tag'],
        ]);
    }

    private function assertStudent(User $user): void
    {
        // FR-1.11: staff passwords are hash-only and are never revealed.
        if (!$user->isStudent()) {
            throw ApiException::forbidden();
        }
    }

    private function assertLoginShape(string $login): void
    {
        if (preg_match('/^\+993\d{8}$/', $login) !== 1) {
            throw ApiException::validation(
                'Telefon belgisi +993 bilen başlap, 8 sana bolmaly.',
                'Номер телефона должен начинаться с +993 и содержать 8 цифр.'
            );
        }
    }

    private function assertPasswordShape(#[SensitiveParameter] string $password): void
    {
        if (mb_strlen($password) < 4) {
            throw ApiException::validation(
                'Parol azyndan 4 belgi bolmaly.',
                'Пароль должен содержать не менее 4 символов.'
            );
        }

        // bcrypt silently ignores everything past 72 bytes. Refusing a
        // longer password is honest; truncating it is a lie the user
        // discovers at login.
        if (strlen($password) > 72) {
            throw ApiException::validation(
                'Parol örän uzyn.',
                'Пароль слишком длинный.'
            );
        }
    }

    private function audit(Scope $scope, string $action, int $entityId, array $meta = []): void
    {
        Capsule::table('audit_log')->insert([
            'actor_id'    => $scope->userId,
            'action'      => $action,
            'entity_type' => 'user',
            'entity_id'   => $entityId,
            'meta'        => $meta === [] ? null : json_encode($meta),
            'ip'          => $meta['ip'] ?? null,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }
}
