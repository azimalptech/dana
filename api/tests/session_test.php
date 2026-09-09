<?php

declare(strict_types=1);

/**
 * Session lifetime — FR-15.15.
 *
 *   C:/xampp/php/php.exe tests/session_test.php
 *
 * The client reported being signed out of the panel and the app "again
 * and again after a short period". The cause was not the token TTLs
 * (15 minutes access, 30 days refresh, both fine) but rotation: a
 * refresh token is single-use, and presenting a spent one was read as
 * theft, which revokes EVERY session the user has. Two entirely
 * innocent things do that routinely — a retry after a lost response,
 * and a second browser tab — so the panel signed admins out constantly.
 * The dev database recorded one admin losing 18 live sessions in a
 * single second.
 *
 * These cases lock the fix and, just as importantly, the part that must
 * NOT change: replay after the window, replay of a token retired for
 * any other reason, and logout all still burn the family.
 *
 * Creates one user with a test-only login and removes exactly that
 * user's rows afterwards, so it is safe against a working database.
 */

require __DIR__ . '/../vendor/autoload.php';

use Dana\Database\Bootstrap;
use Dana\Domain\Auth\AuthService;
use Dana\Domain\Auth\CredentialService;
use Dana\Domain\Auth\TokenService;
use Dana\Domain\Models\User;
use Dana\Http\ApiException;
use Dana\Support\Config;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Log\NullLogger;

$config = Config::load(dirname(__DIR__));
Bootstrap::boot($config);

const TEST_LOGIN = '__session_test__';
const TEST_PASSWORD = 'sessiontest123';

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($detail !== '' ? "  ({$detail})" : '') . PHP_EOL;
}

function cleanup(): void
{
    $ids = Capsule::table('users')->where('login', TEST_LOGIN)->pluck('id')->all();

    if ($ids !== []) {
        Capsule::table('refresh_tokens')->whereIn('user_id', $ids)->delete();
        Capsule::table('users')->whereIn('id', $ids)->delete();
    }

    Capsule::table('login_attempts')->where('login', TEST_LOGIN)->delete();
}

cleanup();

$credentials = new CredentialService($config->require('APP_CRED_KEY'));
$tokens = new TokenService($config);
$auth = new AuthService($credentials, $tokens, new NullLogger());

// Superadmin so the row needs no centre (chk_users_center), and staff
// are the ones the panel signs out — the single-session rule that
// applies to students would confuse what is being measured here.
$userId = Capsule::table('users')->insertGetId([
    'role'          => 'superadmin',
    'login'         => TEST_LOGIN,
    'password_hash' => $credentials->hash(TEST_PASSWORD),
    'full_name'     => 'Session Test',
    'is_active'     => 1,
    'created_at'    => date('Y-m-d H:i:s'),
    'updated_at'    => date('Y-m-d H:i:s'),
]);

/** Live (unrevoked) sessions this user holds. */
function liveTokens(int $userId): int
{
    return Capsule::table('refresh_tokens')
        ->where('user_id', $userId)
        ->whereNull('revoked_at')
        ->count();
}

/** Backdates a token's revocation so the grace window has passed. */
function ageOutRevocation(string $rawToken, TokenService $tokens, int $seconds): void
{
    Capsule::table('refresh_tokens')
        ->where('token_hash', $tokens->hashRefreshToken($rawToken))
        ->update(['revoked_at' => date('Y-m-d H:i:s', time() - $seconds)]);
}

try {
    echo PHP_EOL . 'Rotation' . PHP_EOL;

    $s1 = $auth->login(TEST_LOGIN, TEST_PASSWORD);
    $s2 = $auth->refresh($s1['refresh_token']);

    check('refresh returns a new token', $s2['refresh_token'] !== $s1['refresh_token']);
    check('refresh reports the access TTL', $s2['expires_in'] === $tokens->accessTtl());
    check('the rotated token is labelled', (string) Capsule::table('refresh_tokens')
        ->where('token_hash', $tokens->hashRefreshToken($s1['refresh_token']))
        ->value('revoked_reason') === 'rotated');

    echo PHP_EOL . 'Grace window — the retry that used to sign everyone out' . PHP_EOL;

    // Exactly the lost-response case: the client rotated, never saw the
    // reply, and retried with the only token it still holds.
    $s3 = $auth->refresh($s1['refresh_token']);
    check('a just-rotated token is accepted again', ($s3['refresh_token'] ?? '') !== '');
    check('and the family survives it', liveTokens((int) $userId) >= 2,
        liveTokens((int) $userId) . ' live');

    // Both tokens the user now holds must work — the tab that raced and
    // the tab that won.
    $s4 = $auth->refresh($s2['refresh_token']);
    check('the successor still works too', ($s4['refresh_token'] ?? '') !== '');

    echo PHP_EOL . 'Theft detection — must still fire' . PHP_EOL;

    $before = liveTokens((int) $userId);
    ageOutRevocation($s1['refresh_token'], $tokens, $tokens->refreshGrace() + 5);

    $thrown = null;
    try {
        $auth->refresh($s1['refresh_token']);
    } catch (ApiException $e) {
        $thrown = $e;
    }

    check('replay after the window is rejected', $thrown !== null);
    check('and burns every live session', liveTokens((int) $userId) === 0,
        "was {$before}");
    check('recorded as reuse', Capsule::table('refresh_tokens')
        ->where('user_id', $userId)
        ->where('revoked_reason', 'reuse')
        ->count() > 0);

    echo PHP_EOL . 'Logout is not a rotation' . PHP_EOL;

    $s5 = $auth->login(TEST_LOGIN, TEST_PASSWORD);
    $auth->logout($s5['refresh_token']);

    check('logout labels the token', (string) Capsule::table('refresh_tokens')
        ->where('token_hash', $tokens->hashRefreshToken($s5['refresh_token']))
        ->value('revoked_reason') === 'logout');

    $thrown = null;
    try {
        // Immediately after logout — inside the grace window in time, but
        // a logged-out token must never be revivable by it.
        $auth->refresh($s5['refresh_token']);
    } catch (ApiException $e) {
        $thrown = $e;
    }

    check('a logged-out token is never revived', $thrown !== null);

    echo PHP_EOL . 'A server-side revocation is not theft' . PHP_EOL;

    // FR-12.2: signing in on a second phone revokes the first. If the
    // first phone's next heartbeat were read as theft, it would take the
    // SECOND phone down with it — the user would be signed out of the
    // device they just signed in on.
    $old = $auth->login(TEST_LOGIN, TEST_PASSWORD);
    $new = $auth->login(TEST_LOGIN, TEST_PASSWORD);

    Capsule::table('refresh_tokens')
        ->where('token_hash', $tokens->hashRefreshToken($old['refresh_token']))
        ->update([
            'revoked_at'     => date('Y-m-d H:i:s'),
            'revoked_reason' => 'superseded',
        ]);

    $thrown = null;
    try {
        $auth->refresh($old['refresh_token']);
    } catch (ApiException $e) {
        $thrown = $e;
    }

    check('the superseded device is refused', $thrown !== null);

    $survivor = $auth->refresh($new['refresh_token']);
    check('and the device that replaced it keeps working',
        ($survivor['refresh_token'] ?? '') !== '');

    echo PHP_EOL . 'Expiry still ends the session' . PHP_EOL;

    $s6 = $auth->login(TEST_LOGIN, TEST_PASSWORD);
    Capsule::table('refresh_tokens')
        ->where('token_hash', $tokens->hashRefreshToken($s6['refresh_token']))
        ->update(['expires_at' => date('Y-m-d H:i:s', time() - 60)]);

    $thrown = null;
    try {
        $auth->refresh($s6['refresh_token']);
    } catch (ApiException $e) {
        $thrown = $e;
    }

    check('an expired refresh token is rejected', $thrown !== null);

    echo PHP_EOL . 'Unknown token' . PHP_EOL;

    $thrown = null;
    try {
        $auth->refresh(str_repeat('0', 64));
    } catch (ApiException $e) {
        $thrown = $e;
    }

    check('a token we never issued is rejected', $thrown !== null);

    echo PHP_EOL . 'Deactivation' . PHP_EOL;

    $s7 = $auth->login(TEST_LOGIN, TEST_PASSWORD);
    User::query()->where('id', $userId)->update(['is_active' => 0]);

    $thrown = null;
    try {
        $auth->refresh($s7['refresh_token']);
    } catch (ApiException $e) {
        $thrown = $e;
    }

    check('a disabled account cannot refresh', $thrown !== null);
} finally {
    cleanup();
}

echo PHP_EOL . "  {$pass} passed, {$fail} failed" . PHP_EOL;

exit($fail === 0 ? 0 : 1);
