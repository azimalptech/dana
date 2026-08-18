<?php

declare(strict_types=1);

namespace Dana\Http\Controllers;

use Dana\Domain\Models\User;
use Dana\Domain\Notifications\NotificationService;
use Dana\Http\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {
    }

    /** The in-app inbox — works whether or not push reached the device. */
    public function inbox(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, $this->notifications->inboxFor($this->currentUser($request)));
    }

    public function markRead(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->notifications->markRead($this->currentUser($request), (int) $args['id']);

        return $this->json($response, ['ok' => true]);
    }

    /** The app posts its FCM token here after sign-in and on rotation. */
    public function registerDevice(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        $this->notifications->registerDevice(
            $this->scope($request)->userId,
            trim((string) ($body['token'] ?? '')),
            (string) ($body['platform'] ?? ''),
        );

        return $this->json($response, ['ok' => true]);
    }

    public function send(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json(
            $response,
            $this->notifications->send($this->scope($request), $this->body($request)),
            201
        );
    }

    private function currentUser(ServerRequestInterface $request): User
    {
        $user = User::query()->find($this->scope($request)->userId);

        if ($user === null || !$user->is_active) {
            throw ApiException::accountDisabled();
        }

        return $user;
    }
}
