<?php

declare(strict_types=1);

namespace Dana\Http\Middleware;

use Dana\Domain\Auth\TokenService;
use Dana\Domain\Models\User;
use Dana\Domain\Scope;
use Dana\Http\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Verifies the bearer token and attaches the caller's Scope to the
 * request. Every downstream repository reads that Scope — no controller
 * decides for itself who it is talking to.
 *
 * The token proves IDENTITY only. Role, centre and classroom are read
 * from the database on every request, not from the token's claims:
 * claims are a snapshot from login, so trusting them would let a
 * deactivated account keep working until its token expired, and a
 * student an admin moved between classrooms would keep acting in the
 * old one. One primary-key lookup per request buys away that whole
 * class of staleness.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly TokenService $tokens,
    ) {
    }

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $header = $request->getHeaderLine('Authorization');

        if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            throw ApiException::unauthenticated();
        }

        $claims = $this->tokens->verifyAccessToken($matches[1]);
        $user = User::query()->find((int) $claims->sub);

        if ($user === null) {
            throw ApiException::sessionExpired();
        }

        if (!$user->is_active) {
            // Deactivation takes effect on the next request, not at the
            // token's expiry — that immediacy is the point of course
            // closure (FR-1.14) and of disabling an account at all.
            throw ApiException::accountDisabled();
        }

        $scope = new Scope(
            userId: (int) $user->id,
            role: $user->role,
            centerId: $user->center_id !== null ? (int) $user->center_id : null,
            classroomId: $user->classroom_id !== null ? (int) $user->classroom_id : null,
        );

        return $handler->handle($request->withAttribute(Scope::ATTRIBUTE, $scope));
    }
}
