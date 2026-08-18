<?php

declare(strict_types=1);

namespace Dana\Http\Controllers;

use Dana\Domain\Models\User;
use Dana\Domain\Scope;
use Dana\Http\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

abstract class Controller
{
    protected function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withStatus($status);
    }

    protected function scope(ServerRequestInterface $request): Scope
    {
        $scope = $request->getAttribute(Scope::ATTRIBUTE);

        if (!$scope instanceof Scope) {
            throw ApiException::unauthenticated();
        }

        return $scope;
    }

    /** The signed-in student, or a 403 for anyone else. */
    protected function student(ServerRequestInterface $request): User
    {
        $scope = $this->scope($request);
        $scope->requireRole(User::ROLE_STUDENT);

        $student = User::query()->with('classroom')->find($scope->userId);

        if ($student === null || !$student->is_active) {
            throw ApiException::accountDisabled();
        }

        return $student;
    }

    protected function body(ServerRequestInterface $request): array
    {
        return (array) $request->getParsedBody();
    }
}
