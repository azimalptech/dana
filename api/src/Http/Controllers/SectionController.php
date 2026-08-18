<?php

declare(strict_types=1);

namespace Dana\Http\Controllers;

use Dana\Domain\Progress\SectionAttemptService;
use Dana\Http\ApiException;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The student's section flow (§13): open a section, check single
 * answers as you go, submit the finished attempt, read the history.
 */
final class SectionController extends Controller
{
    public function __construct(
        private readonly SectionAttemptService $attempts,
    ) {
    }

    /** GET /sections/{id} — section info plus the questions to run. */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->json($response, $this->attempts->questionSet(
            $this->student($request),
            (int) $args['id']
        ));
    }

    /**
     * POST /sections/{id}/check — one advisory verdict, nothing stored
     * (FR-13.5). The submit endpoint re-grades everything regardless.
     */
    public function check(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $body = $this->body($request);

        if (!isset($body['question_id'])) {
            throw ApiException::validation('Sorag saýlanmady.', 'Вопрос не выбран.');
        }

        $questionId = (int) $body['question_id'];

        $correct = $this->attempts->check(
            $this->student($request),
            (int) $args['id'],
            $questionId,
            $body['answer'] ?? null
        );

        // The verdict sheet shows the authored explanation after answering
        // (design `Test-correct`/`-wrong`, FR-14.1 answer_description_en).
        // English-only by decision; the app falls back to nothing when a
        // question carries no description.
        $description = Capsule::table('questions')
            ->where('id', $questionId)
            ->value('answer_description_en');

        return $this->json($response, [
            'correct'               => $correct,
            'answer_description_en' => ($description !== null && trim((string) $description) !== '')
                ? (string) $description
                : null,
        ]);
    }

    /** POST /sections/{id}/attempts — the completed run, all answers. */
    public function submit(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $answers = $this->body($request)['answers'] ?? null;

        if (!is_array($answers)) {
            throw ApiException::validation('Jogaplar iberilmedi.', 'Ответы не отправлены.');
        }

        return $this->json($response, $this->attempts->submit(
            $this->student($request),
            (int) $args['id'],
            array_values($answers)
        ));
    }

    /** GET /sections/{id}/attempts — the history modal. */
    public function history(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        return $this->json($response, $this->attempts->history(
            $this->student($request),
            (int) $args['id']
        ));
    }
}
