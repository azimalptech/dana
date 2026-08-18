<?php

declare(strict_types=1);

namespace Dana\Http\Controllers;

use Dana\Domain\Content\ContentRepository;
use Dana\Domain\Progress\StatsService;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class StudentController extends Controller
{
    public function __construct(
        private readonly ContentRepository $content,
        private readonly StatsService $stats,
    ) {
    }

    /**
     * Home screen: the whole level — everything is unlocked (FR-13.3),
     * published sections only.
     */
    public function outline(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, $this->content->outlineFor($this->student($request)));
    }

    public function vocabulary(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->json($response, [
            'items' => $this->content->vocabularyFor($this->student($request), (int) $args['id']),
        ]);
    }

    public function grammar(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->json($response, $this->content->grammarFor($this->student($request), (int) $args['id']));
    }

    // The grammar-guide endpoints (/me/grammar index + topic) were
    // removed with the guide itself (FR-13.26, 2026-08-13). Grammar
    // remains only as a section's practice module; the per-section
    // explanation read above stays for a possible in-player display.

    /** Vocabulary tab: every published word, searchable and filterable. */
    public function dictionary(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();

        return $this->json($response, [
            'items' => $this->content->vocabularyIndexFor(
                $this->student($request),
                trim((string) ($params['q'] ?? '')),
                ($params['bookmarked'] ?? '') === '1',
            ),
        ]);
    }

    /** The per-unit Vocabulary screen. */
    public function unitVocabulary(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $params = $request->getQueryParams();

        return $this->json($response, [
            'items' => $this->content->vocabularyIndexFor(
                $this->student($request),
                null,
                ($params['bookmarked'] ?? '') === '1',
                (int) $args['id'],
            ),
        ]);
    }

    /** FR-6.3: toggle a bookmark. */
    public function toggleBookmark(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $student = $this->student($request);
        $itemId = (int) $args['id'];

        // Without this, any vocabulary id could be bookmarked — including
        // items from sections still in draft.
        $this->content->requireVocabularyAccess($student, $itemId);

        $existing = Capsule::table('student_bookmarks')
            ->where('student_id', $student->id)
            ->where('vocabulary_item_id', $itemId);

        if ($existing->exists()) {
            $existing->delete();

            return $this->json($response, ['bookmarked' => false]);
        }

        Capsule::table('student_bookmarks')->insert([
            'student_id'         => $student->id,
            'vocabulary_item_id' => $itemId,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        return $this->json($response, ['bookmarked' => true]);
    }

    /** FR-13.9/FR-13.19: leaderboard within the student's own classroom. */
    public function leaderboard(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, $this->stats->leaderboard($this->student($request)));
    }

    /** Profile screen: streak and study time. No points (FR-13.7). */
    public function stats(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->json($response, $this->stats->forStudent($this->student($request)));
    }

    /** FR-8.11: study time accrues from foreground heartbeats. */
    public function heartbeat(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $seconds = (int) ($this->body($request)['seconds'] ?? 0);

        return $this->json($response, [
            'seconds_today' => $this->stats->recordStudyTime($this->student($request), $seconds),
        ]);
    }
}
