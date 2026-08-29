<?php

declare(strict_types=1);

namespace Dana\Domain\Accounts;

use Dana\Domain\Auth\CredentialService;
use Dana\Domain\Models\User;
use Dana\Domain\Scope;
use Dana\Http\ApiException;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\QueryException;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * Creating admins and teachers.
 *
 * FR-1.1: superadmin creates admins. FR-1.2: admins create teachers,
 * within their own centre only.
 *
 * Password storage splits by role since FR-13.18: TEACHER passwords are
 * recoverable — the centre admin may view them, exactly like students'
 * (client decision, trade-off stated and accepted). ADMIN passwords stay
 * hash-only and reset-only (superadmin→admin never reveals). Keeping
 * this separate from StudentAccountService still matters: the roles have
 * different rules, and mixing them invites writing reversible ciphertext
 * for an admin by accident.
 */
final class StaffAccountService
{
    /**
     * Same cap and reasoning as the student path: a legitimate admin
     * reads back a forgotten password now and then; a compromised
     * account harvesting the roster does not look like that.
     */
    private const MAX_REVEALS_PER_HOUR = 20;

    public function __construct(
        private readonly CredentialService $credentials,
        private readonly LoggerInterface $log,
    ) {
    }

    public function createAdmin(
        Scope $scope,
        int $centerId,
        string $login,
        #[SensitiveParameter] string $password,
        string $fullName,
    ): User {
        $scope->requireRole(User::ROLE_SUPERADMIN);

        return $this->create($scope, User::ROLE_ADMIN, $centerId, $login, $password, $fullName);
    }

    /**
     * FR-1.2: teachers are hired by the centre admin, not the superadmin.
     * The superadmin's reach stops at creating the centre and its admin —
     * enforced here rather than only hidden in the panel, so the rule
     * holds for anyone calling the API directly.
     */
    public function createTeacher(
        Scope $scope,
        string $login,
        #[SensitiveParameter] string $password,
        string $fullName,
    ): User {
        $scope->requireRole(User::ROLE_ADMIN);

        // FR-12.1: taking the centre from the token rather than the
        // request body removes the possibility of an admin creating a
        // teacher somewhere else.
        if ($scope->centerId === null) {
            throw ApiException::validation('Merkez saýlanmady.', 'Центр не выбран.');
        }

        return $this->create($scope, User::ROLE_TEACHER, $scope->centerId, $login, $password, $fullName);
    }

    /**
     * FR-15.14: the centre admin corrects a teacher's name or phone
     * number in place — same validation as hiring, minus the password.
     */
    public function updateTeacher(Scope $scope, User $teacher, string $login, string $fullName): User
    {
        $allowed = $scope->isSuperadmin()
            || ($scope->isAdmin() && $teacher->center_id === $scope->centerId);

        if (!$allowed || $teacher->role !== User::ROLE_TEACHER) {
            throw ApiException::forbidden();
        }

        $this->assertLogin($login);

        if (trim($fullName) === '') {
            throw ApiException::validation('Ady giriziň.', 'Введите имя.');
        }

        if (User::query()->where('login', $login)->where('id', '!=', $teacher->id)->exists()) {
            throw ApiException::validation(
                "Bu belgi eýýäm ulanylýar: {$login}.",
                "Этот номер уже используется: {$login}."
            );
        }

        $teacher->login = $login;
        $teacher->full_name = trim($fullName);
        $teacher->save();

        return $teacher;
    }

    public function resetPassword(Scope $scope, User $staff, #[SensitiveParameter] string $password): void
    {
        if ($staff->isStudent()) {
            throw ApiException::forbidden();
        }

        // A superadmin may reset anyone; an admin only their own staff.
        $allowed = $scope->isSuperadmin()
            || ($scope->isAdmin() && $staff->center_id === $scope->centerId && $staff->role === User::ROLE_TEACHER);

        if (!$allowed) {
            throw ApiException::forbidden();
        }

        $this->assertPassword($password);

        $staff->password_hash = $this->credentials->hash($password);
        $staff->password_set_at = date('Y-m-d H:i:s');
        // FR-13.18: teachers keep a recoverable copy so their centre
        // admin can view the current password; admins never do.
        $staff->password_ct = null;
        $staff->password_iv = null;
        $staff->password_tag = null;
        $staff->save();

        if ($staff->role === User::ROLE_TEACHER) {
            $this->storeRecoverable($staff, $password);
        }

        Capsule::table('refresh_tokens')
            ->where('user_id', $staff->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => date('Y-m-d H:i:s')]);

        $this->audit($scope, 'staff.password_reset', (int) $staff->id, ['role' => $staff->role]);
        $this->log->info('staff password reset', [
            'actor_id' => $scope->userId,
            'staff_id' => $staff->id,
        ]);
    }

    /**
     * FR-13.18: the centre admin views a TEACHER's current password.
     * Admin passwords stay reset-only, so any other role is refused
     * before permissions are even considered. Audited before the value
     * is returned — a failure to record the reveal is a failure to
     * reveal — and rate-limited exactly like the student path.
     */
    public function reveal(Scope $scope, User $staff, ?string $ip = null): string
    {
        if ($staff->role !== User::ROLE_TEACHER) {
            throw ApiException::forbidden();
        }

        if (!$scope->isAdmin() || (int) $staff->center_id !== (int) $scope->centerId) {
            throw ApiException::forbidden();
        }

        if ($staff->password_ct === null) {
            // Teachers created before recoverable storage (migration 010)
            // have nothing to decrypt. Setting a new password writes the
            // recoverable copy, so the fix is one reset away.
            throw ApiException::validation(
                'Bu mugallymyň paroly görkezip bolmaýar — hasap öň döredilen. Täze parol belläň, şondan soň görkezip bolar.',
                'Пароль этого преподавателя нельзя показать — аккаунт создан раньше. Задайте новый пароль, после этого его можно будет показывать.'
            );
        }

        // The audit log doubles as the rate counter, so the throttle can
        // never disagree with the trail.
        $recent = Capsule::table('audit_log')
            ->where('actor_id', $scope->userId)
            ->where('action', 'staff.password_viewed')
            ->where('created_at', '>=', date('Y-m-d H:i:s', time() - 3600))
            ->count();

        if ($recent >= self::MAX_REVEALS_PER_HOUR) {
            $this->log->warning('staff password reveal throttled', [
                'actor_id' => $scope->userId,
                'reveals'  => $recent,
            ]);
            throw ApiException::tooManyAttempts();
        }

        $this->audit($scope, 'staff.password_viewed', (int) $staff->id, ['ip' => $ip]);

        $this->log->info('staff password revealed', [
            'actor_id' => $scope->userId,
            'staff_id' => $staff->id,
            'ip'       => $ip,
        ]);

        return $this->credentials->decryptFor(
            (int) $staff->id,
            $staff->password_ct,
            $staff->password_iv,
            $staff->password_tag
        );
    }

    private function create(
        Scope $scope,
        string $role,
        int $centerId,
        string $login,
        #[SensitiveParameter] string $password,
        string $fullName,
    ): User {
        $this->assertLogin($login);
        $this->assertPassword($password);

        if (trim($fullName) === '') {
            throw ApiException::validation('Ady giriziň.', 'Введите имя.');
        }

        if (User::query()->where('login', $login)->exists()) {
            throw ApiException::validation(
                "Bu belgi eýýäm ulanylýar: {$login}.",
                "Этот номер уже используется: {$login}."
            );
        }

        $now = date('Y-m-d H:i:s');

        $user = new User();
        $user->role = $role;
        $user->center_id = $centerId;
        $user->classroom_id = null;
        $user->login = $login;
        $user->password_hash = $this->credentials->hash($password);
        $user->password_ct = null;
        $user->password_iv = null;
        $user->password_tag = null;
        $user->password_set_at = $now;
        $user->full_name = trim($fullName);
        $user->is_active = true;
        $user->created_by = $scope->userId;
        $user->created_at = $now;
        $user->updated_at = $now;

        try {
            $user->save();
        } catch (QueryException $e) {
            // Racing past the exists() check lands on the unique login
            // key — answer as the check would have, not with a 500.
            if (($e->errorInfo[0] ?? '') === '23000') {
                throw ApiException::validation(
                    "Bu belgi eýýäm ulanylýar: {$login}.",
                    "Этот номер уже используется: {$login}."
                );
            }

            throw $e;
        }

        // FR-13.18: the recoverable copy binds to the user id, so it can
        // only be written after the insert. Teachers only — an admin row
        // holding ciphertext would trip chk_users_password_ct, by design.
        if ($role === User::ROLE_TEACHER) {
            $this->storeRecoverable($user, $password);
        }

        $this->audit($scope, $role . '.created', (int) $user->id, ['center_id' => $centerId]);

        return $user;
    }

    private function storeRecoverable(User $staff, #[SensitiveParameter] string $password): void
    {
        $encrypted = $this->credentials->encryptFor((int) $staff->id, $password);

        Capsule::table('users')->where('id', $staff->id)->update([
            'password_ct'  => $encrypted['ct'],
            'password_iv'  => $encrypted['iv'],
            'password_tag' => $encrypted['tag'],
        ]);
    }

    private function assertLogin(string $login): void
    {
        if (preg_match('/^\+993\d{8}$/', $login) !== 1) {
            throw ApiException::validation(
                'Telefon belgisi +993 bilen başlap, 8 sana bolmaly.',
                'Номер телефона должен начинаться с +993 и содержать 8 цифр.'
            );
        }
    }

    private function assertPassword(#[SensitiveParameter] string $password): void
    {
        // Staff accounts can change a whole centre's data, so they are
        // held to a longer password than students.
        if (mb_strlen($password) < 8) {
            throw ApiException::validation(
                'Işgär paroly azyndan 8 belgi bolmaly.',
                'Пароль сотрудника должен содержать не менее 8 символов.'
            );
        }

        // bcrypt ignores everything past 72 bytes — refuse rather than
        // silently truncate.
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
            // The dedicated, indexed ip column — the reveal path passes
            // it in meta; mirror it here so IP forensics don't have to
            // dig through JSON (FR-1.12).
            'ip'          => $meta['ip'] ?? null,
            'meta'        => $meta === [] ? null : json_encode($meta),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }
}
