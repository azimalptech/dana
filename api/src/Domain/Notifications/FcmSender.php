<?php

declare(strict_types=1);

namespace Dana\Domain\Notifications;

use Firebase\JWT\JWT;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Firebase Cloud Messaging, HTTP v1.
 *
 * The old `key=SERVER_KEY` endpoint was retired in 2024, so this uses the
 * v1 API: sign a JWT with the service account key, exchange it for an
 * OAuth access token, then post messages. APNs is reached through FCM as
 * well, so one path covers both platforms.
 *
 * Push is the primary channel (FR-10.3) but never the only one — the
 * in-app inbox is written whether or not any of this succeeds, so a
 * missing key, a denied OS permission or a throttled send costs
 * timeliness, not the message.
 */
final class FcmSender
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** FCM accepts at most 500 tokens per multicast. */
    private const BATCH = 500;

    private ?string $accessToken = null;
    private int $tokenExpiresAt = 0;

    public function __construct(
        private readonly ?string $serviceAccountPath,
        private readonly LoggerInterface $log,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->serviceAccountPath !== null && is_file($this->serviceAccountPath);
    }

    /**
     * @param list<string> $tokens
     * @return array{sent: int, failed: int, invalid: list<string>}
     */
    public function send(array $tokens, string $title, string $body, array $data = []): array
    {
        if (!$this->isConfigured() || $tokens === []) {
            return ['sent' => 0, 'failed' => 0, 'invalid' => []];
        }

        $account = $this->serviceAccount();
        $project = (string) ($account['project_id'] ?? '');
        $url = "https://fcm.googleapis.com/v1/projects/{$project}/messages:send";

        $sent = 0;
        $failed = 0;
        $invalid = [];

        foreach (array_chunk($tokens, self::BATCH) as $chunk) {
            foreach ($chunk as $token) {
                $payload = [
                    'message' => [
                        'token'        => $token,
                        'notification' => ['title' => $title, 'body' => $body],
                        'data'         => array_map('strval', $data),
                        'android'      => ['priority' => 'high'],
                        'apns'         => [
                            'headers' => ['apns-priority' => '10'],
                            'payload' => ['aps' => ['sound' => 'default']],
                        ],
                    ],
                ];

                [$status, $response] = $this->post($url, $payload);

                if ($status >= 200 && $status < 300) {
                    $sent++;
                    continue;
                }

                $failed++;
                $code = $response['error']['details'][0]['errorCode']
                    ?? $response['error']['status']
                    ?? '';

                // The device uninstalled or the token rotated. Collecting
                // these lets the caller prune them, so a dead token is
                // not retried on every future announcement.
                if (in_array($code, ['UNREGISTERED', 'INVALID_ARGUMENT', 'NOT_FOUND'], true)) {
                    $invalid[] = $token;
                }
            }
        }

        $this->log->info('push sent', ['sent' => $sent, 'failed' => $failed, 'pruned' => count($invalid)]);

        return ['sent' => $sent, 'failed' => $failed, 'invalid' => $invalid];
    }

    /** @return array{0: int, 1: array} */
    private function post(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->accessToken(),
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, json_decode((string) $raw, true) ?? []];
    }

    /** Cached until shortly before expiry, so one token covers a batch. */
    private function accessToken(): string
    {
        if ($this->accessToken !== null && time() < $this->tokenExpiresAt - 60) {
            return $this->accessToken;
        }

        $account = $this->serviceAccount();
        $now = time();

        $assertion = JWT::encode(
            [
                'iss'   => $account['client_email'],
                'scope' => self::SCOPE,
                'aud'   => self::TOKEN_URL,
                'iat'   => $now,
                'exp'   => $now + 3600,
            ],
            (string) $account['private_key'],
            'RS256'
        );

        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $assertion,
            ]),
        ]);

        $raw = curl_exec($ch);
        curl_close($ch);

        $body = json_decode((string) $raw, true) ?? [];

        if (!isset($body['access_token'])) {
            throw new RuntimeException(
                'FCM token exchange failed: ' . ($body['error_description'] ?? 'unknown error')
            );
        }

        $this->accessToken = (string) $body['access_token'];
        $this->tokenExpiresAt = $now + (int) ($body['expires_in'] ?? 3600);

        return $this->accessToken;
    }

    private function serviceAccount(): array
    {
        $json = json_decode((string) file_get_contents((string) $this->serviceAccountPath), true);

        if (!is_array($json) || !isset($json['client_email'], $json['private_key'], $json['project_id'])) {
            throw new RuntimeException(
                'FCM service account file is not a valid Firebase key JSON.'
            );
        }

        return $json;
    }
}
