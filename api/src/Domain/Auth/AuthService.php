<?php

declare(strict_types=1);

namespace Dana\Domain\Auth;

use Dana\Domain\Models\User;
use Dana\Http\ApiException;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * Login, refresh and logout.
 *
 * Authentication compares the bcrypt hash and nothing else. The
 * reversible copy used for the teacher reveal (FR-1.10) is never touched
 * here, so a defect in that feature cannot become an auth bypass.
 */
final class AuthService
{
    /**
     * A bcrypt hash of a random string, used to spend the same time
     * verifying a nonexistent login as a real one. Without it, response
     * timing reveals which logins exist.
     */
    private const DUMMY_HASH = '$2y$12$usesomesillystringfoeulUSw2C2Nc5jHqM.4PPxKUyLW0PPHWr7O';

    /**
     * Logins are phone numbers in a known format and passwords are short
     * by policy, so the credential space is small enough to grind
     * through. Throttled per (login, ip) so an attacker hammering one
     * number cannot lock the real owner out from their own device.
     */
    private const MAX_FAILURES = 10;
    private const WINDOW_MINUTES = 15;

    /**
     * Second cap across ALL sources. The per-(login, ip) cap protects a
     * student from being locked out by one abusive stranger, but alone it
     * lets an attacker rotate IPs and keep guessing the same number
     * forever. Past this many failures on one login from anywhere, the
     * account cools down regardless of where the attempts come from.
     */
    private const MAX_FAILURES_ANY_IP = 40;

    /**
     * A cap on failed attempts from ONE ip regardless of which login they
     * targeted. The per-login caps above do nothing against an attacker
     * who sends a different (often random) login every request: each such
     * login has no history, so the throttle never fires and every request
     * forces a cost-12 bcrypt — a cheap way to exhaust host CPU. This cap
     * is keyed on ip alone and evaluated before any password check.
     */
    private const MAX_FAILURES_PER_IP = 30;

    public function __construct(
        private readonly CredentialService $credentials,
        private readonly TokenService $tokens,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, user: User}
     */
    public function login(
        string $login,
        #[SensitiveParameter] string $password,
        ?string $deviceInfo = null,
        ?string $ip = null,
    ): array {
        $this->assertNotThrottled($login, $ip);

        $user = User::query()->where('login', $login)->first();

        if ($user === null) {
            // Burn equivalent time, then fail identically to a wrong password.
            $this->credentials->verify($password, self::DUMMY_HASH);
            $this->recordAttempt($login, $ip, false);
            $this->log->warning('login failed', [
                'login'  => $login,
                'reason' => 'no_such_login',
                'ip'     => $ip,
            ]);
            throw ApiException::invalidCredentials();
        }

        if (!$this->credentials->verify($password, $user->password_hash)) {
            $this->recordAttempt($login, $ip, false);
            $this->log->warning('login failed', [
                'login'   => $login,
                'user_id' => $user->id,
                'reason'  => 'bad_password',
                'ip'      => $ip,
            ]);
            throw ApiException::invalidCredentials();
        }

        // Checked only after the password matches, so the message cannot
        // be used to enumerate which accounts exist.
        if (!$user->is_active) {
            $this->log->warning('login failed', [
                'login'   => $login,
                'user_id' => $user->id,
                'reason'  => 'account_disabled',
                'ip'      => $ip,
            ]);
            throw ApiException::accountDisabled();
        }

        $session = $this->startSession($user, $deviceInfo);
        $this->recordAttempt($login, $ip, true);

        $this->log->info('login ok', [
            'user_id' => $user->id,
            'role'    => $user->role,
            'login'   => $user->login,
            'ip'      => $ip,
        ]);

        return $session;
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, user: User}
     */
    public function refresh(string $refreshToken, ?string $deviceInfo = null): array
    {
        $hash = $this->tokens->hashRefreshToken($refreshToken);
        $now = date('Y-m-d H:i:s');

        $known = Capsule::table('refresh_tokens')->where('token_hash', $hash)->first();

        if ($known !== null && $known->revoked_at !== null) {
            // A revoked token presented again is EITHER theft or, far more
            // often, one of two innocent things: the client rotated, the
            // response was lost on the way back, and it retried with the
            // only token it still has; or a second browser tab is using
            // the copy it read at page load. Both were being punished as
            // theft — and the punishment is the whole family, so every
            // device signs out at once. That is what "logged out again and
            // again" was (FR-15.15): the dev database holds one admin who
            // lost 18 live sessions in a single second, and a teacher 14.
            //
            // Only a token retired by ROTATION gets the benefit of the
            // doubt, and only inside a short window. Replayed a day
            // later it is refused, and that IS the theft signature the
            // detection was written for.
            $graceEnds = strtotime((string) $known->revoked_at) + $this->tokens->refreshGrace();

            if ($known->revoked_reason === 'rotated' && time() <= $graceEnds) {
                $user = User::query()->find($known->user_id);

                if ($user === null || !$user->is_active) {
                    throw ApiException::sessionExpired();
                }

                $this->log->info('refresh replayed inside the rotation grace window', [
                    'user_id'  => $user->id,
                    'token_id' => $known->id,
                ]);

                return $this->startSession(
                    $user,
                    $deviceInfo,
                    revokeExisting: false,
                    parentId: (int) $known->id,
                );
            }

            // Everything the SERVER retired — a logout, the FR-12.2
            // single-session rule, a password reset, a deletion — is a
            // stale client, not a thief, and burning the family for one
            // is a false positive with teeth: a student signing in on a
            // second phone revokes the first, and the first phone's next
            // heartbeat would then log the SECOND one out. Refuse the
            // token, leave the rest of the family alone.
            if ($known->revoked_reason !== null && $known->revoked_reason !== 'rotated') {
                $this->log->info('refresh with a server-revoked token', [
                    'user_id'  => $known->user_id,
                    'token_id' => $known->id,
                    'reason'   => $known->revoked_reason,
                ]);

                throw ApiException::sessionExpired();
            }

            // A rotated token replayed long after the fact, or one from
            // before migration 015 whose reason cannot be known: the
            // conservative reading is theft.
            Capsule::table('refresh_tokens')
                ->where('user_id', $known->user_id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now, 'revoked_reason' => 'reuse']);

            $this->log->warning('refresh token reuse — family revoked', [
                'user_id'  => $known->user_id,
                'token_id' => $known->id,
                'reason'   => $known->revoked_reason ?? 'unknown',
                'age'      => time() - strtotime((string) $known->revoked_at),
            ]);

            throw ApiException::sessionExpired();
        }

        // Rotation, made atomic: spend the token with a conditional UPDATE
        // and only proceed if THIS request is the one that flipped it.
        // Two concurrent refreshes with the same token then cannot both
        // mint — the loser sees zero affected rows. The loser is not
        // rejected outright any more: it re-reads the row it lost to and
        // takes the grace path above, because a client that raced itself
        // has done nothing wrong.
        $spent = Capsule::table('refresh_tokens')
            ->where('token_hash', $hash)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', $now)
            ->update(['revoked_at' => $now, 'revoked_reason' => 'rotated']);

        $row = Capsule::table('refresh_tokens')->where('token_hash', $hash)->first();

        if ($spent !== 1) {
            // Unknown token, or expired past its 30 days: nothing to do.
            if ($row === null || strtotime((string) $row->expires_at) <= time()) {
                throw ApiException::sessionExpired();
            }

            // Known, live a moment ago, now revoked by the request that
            // beat us. Recurse once so the single grace decision above
            // covers this path too rather than duplicating it here.
            return $this->refresh($refreshToken, $deviceInfo);
        }

        $user = $row === null ? null : User::query()->find($row->user_id);

        if ($user === null || !$user->is_active) {
            throw ApiException::sessionExpired();
        }

        return $this->startSession(
            $user,
            $deviceInfo,
            revokeExisting: false,
            parentId: (int) $row->id,
        );
    }

    public function logout(string $refreshToken): void
    {
        Capsule::table('refresh_tokens')
            ->where('token_hash', $this->tokens->hashRefreshToken($refreshToken))
            ->whereNull('revoked_at')
            ->update(['revoked_at' => date('Y-m-d H:i:s'), 'revoked_reason' => 'logout']);
    }

    private function assertNotThrottled(string $login, ?string $ip): void
    {
        $since = date('Y-m-d H:i:s', time() - self::WINDOW_MINUTES * 60);

        // IP-only cap first, and independent of the login value, so a
        // stream of random logins from one source is stopped before it
        // reaches the expensive bcrypt path (DoS defence).
        if ($ip !== null) {
            $fromIp = Capsule::table('login_attempts')
                ->where('ip', $ip)
                ->where('succeeded', 0)
                ->where('attempted_at', '>=', $since)
                ->count();

            if ($fromIp >= self::MAX_FAILURES_PER_IP) {
                $this->log->warning('login throttled (ip)', ['ip' => $ip, 'from_ip' => $fromIp]);
                throw ApiException::tooManyAttempts();
            }
        }

        $rows = Capsule::table('login_attempts')
            ->where('login', $login)
            ->where('succeeded', 0)
            ->where('attempted_at', '>=', $since)
            ->get(['ip']);

        $fromThisIp = $rows->where('ip', $ip)->count();

        if ($fromThisIp >= self::MAX_FAILURES || $rows->count() >= self::MAX_FAILURES_ANY_IP) {
            $this->log->warning('login throttled', [
                'login'        => $login,
                'ip'           => $ip,
                'from_this_ip' => $fromThisIp,
                'from_all_ips' => $rows->count(),
            ]);
            throw ApiException::tooManyAttempts();
        }
    }

    private function recordAttempt(string $login, ?string $ip, bool $succeeded): void
    {
        Capsule::table('login_attempts')->insert([
            'login'        => mb_substr($login, 0, 20),
            'ip'           => $ip,
            'succeeded'    => $succeeded ? 1 : 0,
            'attempted_at' => date('Y-m-d H:i:s'),
        ]);

        // A success clears the counter, so a user who mistypes a few
        // times then gets it right is not still throttled afterwards.
        if ($succeeded) {
            Capsule::table('login_attempts')
                ->where('login', $login)
                ->where('ip', $ip)
                ->where('succeeded', 0)
                ->delete();
        }

        // Cheap opportunistic cleanup — no cron needed for this table.
        if (random_int(1, 50) === 1) {
            Capsule::table('login_attempts')
                ->where('attempted_at', '<', date('Y-m-d H:i:s', time() - 86400))
                ->delete();
        }
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, user: User}
     */
    private function startSession(
        User $user,
        ?string $deviceInfo,
        bool $revokeExisting = true,
        ?int $parentId = null,
    ): array {
        $now = date('Y-m-d H:i:s');

        // FR-12.2: a student holds one active session. Logging in
        // elsewhere ends the previous one, which is what stops an account
        // being shared around a classroom. Staff are unrestricted.
        if ($revokeExisting && $user->isStudent()) {
            $ended = Capsule::table('refresh_tokens')
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now, 'revoked_reason' => 'superseded']);

            if ($ended > 0) {
                // Worth seeing in the log: repeated occurrences on one
                // account usually mean credentials are being shared.
                $this->log->info('previous student session ended', [
                    'user_id'  => $user->id,
                    'sessions' => $ended,
                ]);
            }
        }

        $refresh = $this->tokens->issueRefreshToken();

        Capsule::table('refresh_tokens')->insert([
            'user_id'     => $user->id,
            'parent_id'   => $parentId,
            'token_hash'  => $refresh['hash'],
            'device_info' => $deviceInfo !== null ? mb_substr($deviceInfo, 0, 255) : null,
            'expires_at'  => $refresh['expires_at'],
            'created_at'  => $now,
        ]);

        $user->last_login_at = $now;
        $user->save();

        return [
            'access_token'  => $this->tokens->issueAccessToken($user),
            'refresh_token' => $refresh['token'],
            'expires_in'    => $this->tokens->accessTtl(),
            'user'          => $user,
        ];
    }
}
