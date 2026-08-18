<?php

declare(strict_types=1);

namespace Dana\Http\Controllers;

use Dana\Domain\Models\User;
use Dana\Domain\Progress\QuizDrawService;
use Dana\Http\ApiException;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The superadmin's control over a child unit's fixed Exam Quiz draw
 * (docs/06-CONTENT-V2.md §3, FR-14.3).
 */
final class QuizController extends Controller
{
    public function __construct(private readonly QuizDrawService $draws)
    {
    }

    /**
     * POST /manage/quiz/{childUnitId}/redraw — clears the stored draw and
     * makes a fresh one, returning the new set (ids, external codes and
     * per-skill counts). Superadmin only.
     */
    public function redraw(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->scope($request)->requireRole(User::ROLE_SUPERADMIN);

        $childUnitId = (int) ($args['childUnitId'] ?? 0);

        if (!Capsule::table('unit_sections')->where('id', $childUnitId)->exists()) {
            throw ApiException::notFound();
        }

        return $this->json($response, $this->draws->redraw($childUnitId));
    }
}
