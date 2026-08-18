<?php

declare(strict_types=1);

namespace Dana\Http\Controllers;

use Dana\Domain\Auth\AuthService;
use Dana\Domain\Models\User;
use Dana\Domain\Scope;
use Dana\Http\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
    ) {
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $login = trim((string) ($body['login'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        if ($login === '' || $password === '') {
            throw ApiException::validation(
                'Login we paroly giriziň.',
                'Введите логин и пароль.'
            );
        }

        $result = $this->auth->login(
            $login,
            $password,
            $request->getHeaderLine('User-Agent') ?: null,
            $this->clientIp($request)
        );

        return $this->json($response, [
            'access_token'  => $result['access_token'],
            'refresh_token' => $result['refresh_token'],
            'token_type'    => 'Bearer',
            'expires_in'    => $result['expires_in'],
            'user'          => $this->publicUser($result['user']),
        ]);
    }

    public function refresh(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $token = trim((string) ($body['refresh_token'] ?? ''));

        if ($token === '') {
            throw ApiException::validation(
                'Täzeleme belgisi ýok.',
                'Отсутствует токен обновления.'
            );
        }

        $result = $this->auth->refresh($token, $request->getHeaderLine('User-Agent') ?: null);

        return $this->json($response, [
            'access_token'  => $result['access_token'],
            'refresh_token' => $result['refresh_token'],
            'token_type'    => 'Bearer',
            'expires_in'    => $result['expires_in'],
        ]);
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $token = trim((string) ($body['refresh_token'] ?? ''));

        if ($token !== '') {
            $this->auth->logout($token);
        }

        return $this->json($response, ['ok' => true]);
    }

    public function me(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $request->getAttribute(Scope::ATTRIBUTE);
        assert($scope instanceof Scope);

        $user = User::query()->find($scope->userId);

        if ($user === null) {
            throw ApiException::notFound();
        }

        return $this->json($response, ['user' => $this->publicUser($user)]);
    }

    /**
     * Deliberately explicit rather than returning the model: credential
     * columns must never reach a response by accident (FR-1.10).
     */
    private function publicUser(User $user): array
    {
        return [
            'id'             => $user->id,
            'role'           => $user->role,
            'login'          => $user->login,
            'full_name'      => $user->full_name,
            'center_id'      => $user->center_id,
            'classroom_id'   => $user->classroom_id,
            'interface_lang' => $user->interface_lang,
        ];
    }

    /**
     * The throttle key and audit IP is the DIRECT peer address only.
     *
     * X-Forwarded-For is client-supplied and trivially spoofed: folding
     * it into this value let an attacker mint a fresh throttle bucket per
     * request (defeating the per-IP cap) and, worse, overflow the
     * login_attempts.ip column so the failed attempt threw instead of
     * being recorded — disengaging the throttle entirely. It is dropped
     * here; capture it in a header log upstream if a trusted proxy needs
     * the original client address.
     */
    private function clientIp(ServerRequestInterface $request): ?string
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        // Never exceed login_attempts.ip (varchar 45) — a malformed peer
        // address must not turn a login into a 500.
        return $remote === null ? null : mb_substr($remote, 0, 45);
    }

    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status);
    }
}
