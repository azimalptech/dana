<?php

declare(strict_types=1);

namespace Dana\Domain\Auth;

use Dana\Domain\Models\User;
use Dana\Http\ApiException;
use Dana\Support\Config;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use stdClass;
use Throwable;

/**
 * Short-lived access tokens, long-lived rotating refresh tokens.
 *
 * The access token carries the scope claims the API authorises against:
 * role, centre and classroom. They are read from the token rather than
 * re-queried per request, so a request cannot be authorised against a
 * different scope than the one it was issued for.
 */
final class TokenService
{
    private const ALGORITHM = 'HS256';

    public function __construct(
        private readonly Config $config,
    ) {
    }

    public function issueAccessToken(User $user): string
    {
        $now = time();

        return JWT::encode(
            [
                'sub'          => (string) $user->id,
                'role'         => $user->role,
                'center_id'    => $user->center_id,
                'classroom_id' => $user->classroom_id,
                'iat'          => $now,
                'exp'          => $now + $this->accessTtl(),
            ],
            $this->secret(),
            self::ALGORITHM
        );
    }

    /**
     * Returns the opaque refresh token plus the hash to store. The raw
     * token is never persisted — only its SHA-256 — so a leaked database
     * cannot be used to mint sessions.
     *
     * @return array{token: string, hash: string, expires_at: string}
     */
    public function issueRefreshToken(): array
    {
        $token = bin2hex(random_bytes(32));

        return [
            'token'      => $token,
            'hash'       => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', time() + $this->refreshTtl()),
        ];
    }

    public function hashRefreshToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function verifyAccessToken(string $jwt): stdClass
    {
        try {
            return JWT::decode($jwt, new Key($this->secret(), self::ALGORITHM));
        } catch (Throwable) {
            // Expired, malformed, wrong signature — the client's remedy
            // is identical in every case: refresh, then log in again.
            throw ApiException::sessionExpired();
        }
    }

    public function accessTtl(): int
    {
        return $this->config->int('JWT_ACCESS_TTL', 900);
    }

    public function refreshTtl(): int
    {
        return $this->config->int('JWT_REFRESH_TTL', 2592000);
    }

    /**
     * How long a just-rotated refresh token may still be presented
     * without being read as theft (FR-15.15).
     *
     * Sized for the two innocent replays it exists for — a retry after a
     * lost response, and a second browser tab that read the token at page
     * load — both of which happen within seconds. A minute is generous
     * for those and useless to an attacker, who would have to replay a
     * stolen token inside the same minute AND before its owner does.
     */
    public function refreshGrace(): int
    {
        return max(0, $this->config->int('JWT_REFRESH_GRACE', 60));
    }

    private function secret(): string
    {
        return $this->config->require('JWT_SECRET');
    }
}
