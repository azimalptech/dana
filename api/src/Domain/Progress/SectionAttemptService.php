<?php

declare(strict_types=1);

namespace Dana\Domain\Progress;

use Dana\Domain\Content\ContentRepository;
use Dana\Domain\Models\ExerciseSet;
use Dana\Domain\Models\Question;
use Dana\Domain\Models\Section;
use Dana\Domain\Models\User;
use Dana\Http\ApiException;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Collection;

/**
 * Section attempts — the §13 progress engine.
 *
 * Three rules drive everything here:
 *
 *  FR-13.5  Nothing is saved mid-section. check() grades one answer and
 *           stores NOTHING; submit() is the only write, and it wants an
 *           answer for every question or refuses the lot.
 *  FR-13.6  Sections repeat without limit. Every completed run is a new
 *           attempt row; the shown result is the average of all of them.
 *  FR-13.7  No points. A submission produces a percentage, full stop.
 *
 * The quiz composition rule (FR-13.4) also lives here: an Exam Quiz has
 * no questions of its own — it serves every quiz-eligible question of
 * its sibling sections, in source order, without shuffle.
 */
final class SectionAttemptService
{
    private readonly QuizDrawService $quizDraw;

    public function __construct(
        private readonly Grader $grader,
        private readonly ContentRepository $content,
        ?QuizDrawService $quizDraw = null,
    ) {
        // Optional so existing callers (`new SectionAttemptService(grader,
        // content)`) keep working; the draw service holds no state.
        $this->quizDraw = $quizDraw ?? new QuizDrawService();
    }

    /** GET /sections/{id} — the section plus its questions, ready to run. */
    public function questionSet(User $student, int $sectionId): array
    {
        $section = $this->content->requireSection($student, $sectionId);
        $questions = $this->questionsFor($section);
        $lang = (string) ($student->interface_lang ?? 'tk');

        $stat = Capsule::table('student_section_stats')
            ->where('student_id', $student->id)
            ->where('section_id', $section->id)
            ->first();

        $attempts = (int) ($stat->attempts ?? 0);

        return [
            'section' => [
                'id'             => (int) $section->id,
                'type'           => $section->type,
                'title_tk'       => $section->title_tk,
                'title_ru'       => $section->title_ru,
                'title_en'       => null,
                'label'          => $section->unit_number . '-' . $section->unit_code,
                'question_count' => count($questions),
                'attempts'       => $attempts,
                'average'        => $attempts === 0
                    ? null
                    : round((float) $stat->percent_sum / $attempts, 1),
            ],
            'questions' => $questions->map(fn (Question $q): array => [
                'id'              => (int) $q->id,
                // The set's exercise type — a section mixes types, so
                // each question says how it wants to be rendered.
                'type'            => (string) $q->exercise_type,
                // FR-13.13: the media type (text / audio / picture).
                'question_type'   => (string) $q->question_type,
                'media_path'      => $q->media_path,
                'prompt_tk'       => $q->prompt_tk,
                'prompt_ru'       => $q->prompt_ru,
                'pair_mode'       => $q->pair_mode,
                'input_mode'      => $q->input_mode,
                // Answer stripped — grading is server-side (NFR-5).
                'payload'         => $q->payloadForStudent((string) $q->exercise_type, $lang),
            ])->values()->all(),
        ];
    }

    /**
     * POST /sections/{id}/check — grades ONE answer and stores nothing
     * (FR-13.5). This backs the per-question verdict UI; the verdict it
     * returns is advisory, because submit() re-grades everything anyway.
     */
    public function check(User $student, int $sectionId, int $questionId, mixed $answer): bool
    {
        $section = $this->content->requireSection($student, $sectionId);

        $question = $this->questionQuery($section)
            ->where('questions.id', $questionId)
            ->first();

        if ($question === null || !Question::payloadServable($question->payload)) {
            // Not part of this section's composition, or auto-hidden until
            // its media is uploaded (§3) — a probe or a stale client. Same
            // non-answer as any other missing thing.
            throw ApiException::notFound();
        }

        return $this->grader->isCorrect((string) $question->exercise_type, $question, $answer);
    }

    /**
     * POST /sections/{id}/attempts — the ONLY write in the whole flow.
     *
     * Requires an answer for every question of the section: an attempt
     * is complete or it does not exist (FR-13.5). Everything is
     * re-graded server-side and written in one transaction — attempt,
     * per-question answers, stats upsert — so a failure part way cannot
     * leave a half-recorded run.
     *
     * @param list<array{question_id: int|string, answer: mixed}> $answers
     */
    public function submit(User $student, int $sectionId, array $answers): array
    {
        $section = $this->content->requireSection($student, $sectionId);
        $questions = $this->questionsFor($section);

        if ($questions->isEmpty()) {
            throw ApiException::notFound();
        }

        $given = [];

        foreach ($answers as $entry) {
            if (!is_array($entry) || !isset($entry['question_id'])) {
                throw $this->incompleteAttempt();
            }

            $given[(int) $entry['question_id']] = $entry['answer'] ?? null;
        }

        // Every question answered, and nothing that is not part of the
        // section — an id from elsewhere is either a bug or a probe.
        if (count($given) !== $questions->count()) {
            throw $this->incompleteAttempt();
        }

        foreach ($questions as $question) {
            if (!array_key_exists((int) $question->id, $given)) {
                throw $this->incompleteAttempt();
            }
        }

        $rows = [];
        $correct = 0;

        foreach ($questions->values() as $i => $question) {
            $answer = $given[(int) $question->id];
            $isCorrect = $this->grader->isCorrect((string) $question->exercise_type, $question, $answer);

            if ($isCorrect) {
                $correct++;
            }

            $rows[] = [
                'question_id' => (int) $question->id,
                'given'       => (string) json_encode($answer, JSON_UNESCAPED_UNICODE),
                'is_correct'  => $isCorrect ? 1 : 0,
                'sort_order'  => $i,
            ];
        }

        $total = $questions->count();
        $percent = round($correct / $total * 100, 2);
        $now = date('Y-m-d H:i:s');

        return Capsule::connection()->transaction(function () use ($student, $section, $rows, $correct, $total, $percent, $now): array {
            // Locked so two simultaneous submissions cannot claim the
            // same attempt_no (the unique key would fail the loser with
            // a 500 instead of just queueing it behind the winner).
            $attemptNo = (int) Capsule::table('section_attempts')
                ->where('student_id', $student->id)
                ->where('section_id', $section->id)
                ->lockForUpdate()
                ->max('attempt_no') + 1;

            $attemptId = Capsule::table('section_attempts')->insertGetId([
                'student_id'     => $student->id,
                'section_id'     => $section->id,
                'classroom_id'   => $student->classroom_id,
                'attempt_no'     => $attemptNo,
                'question_count' => $total,
                'correct_count'  => $correct,
                'percent'        => $percent,
                'completed_at'   => $now,
                'created_at'     => $now,
            ]);

            Capsule::table('attempt_answers')->insert(array_map(
                static fn (array $row): array => $row + ['attempt_id' => $attemptId],
                $rows
            ));

            // NFR-2: the aggregate every rollup reads instead of
            // scanning the attempt log.
            Capsule::connection()->statement(
                'INSERT INTO student_section_stats
                    (student_id, section_id, classroom_id, attempts, percent_sum, last_completed_at)
                 VALUES (?, ?, ?, 1, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    attempts          = attempts + 1,
                    percent_sum       = percent_sum + VALUES(percent_sum),
                    last_completed_at = VALUES(last_completed_at)',
                [$student->id, $section->id, $student->classroom_id, $percent, $now]
            );

            // FR-8.10 still governs the streak: a completed section
            // attempt marks the day, decided server-side in Asia/Ashgabat.
            Capsule::connection()->statement(
                'INSERT INTO student_activity_days
                    (student_id, activity_date, seconds_studied, exercises_completed)
                 VALUES (?, ?, 0, 1)
                 ON DUPLICATE KEY UPDATE exercises_completed = exercises_completed + 1',
                [$student->id, substr($now, 0, 10)]
            );

            $stat = Capsule::table('student_section_stats')
                ->where('student_id', $student->id)
                ->where('section_id', $section->id)
                ->first();

            return [
                'attempt_no' => $attemptNo,
                'percent'    => (float) $percent,
                'correct'    => $correct,
                'total'      => $total,
                'average'    => round((float) $stat->percent_sum / (int) $stat->attempts, 1),
                'history'    => $this->historyRows($student, (int) $section->id),
            ];
        });
    }

    /** GET /sections/{id}/attempts — the history modal. */
    public function history(User $student, int $sectionId): array
    {
        $section = $this->content->requireSection($student, $sectionId);

        $stat = Capsule::table('student_section_stats')
            ->where('student_id', $student->id)
            ->where('section_id', $section->id)
            ->first();

        return [
            'average' => $stat === null
                ? null
                : round((float) $stat->percent_sum / (int) $stat->attempts, 1),
            'attempts' => $this->historyRows($student, (int) $section->id),
        ];
    }

    /**
     * FR-13.4 + FR-4.17: the questions a student actually receives.
     *
     * Non-quiz: the section's own active questions from published sets.
     * Quiz: every quiz-eligible question of the SIBLING sections of the
     * same child unit, source order, no shuffle — drafts excluded, so a
     * question can never reach a student through the quiz before its own
     * section is published.
     *
     * @return Collection<int, Question> hydrated with exercise-set
     *         columns: exercise_type, pair_mode, input_mode
     */
    public function questionsFor(object $section): Collection
    {
        // §3: a question whose audio/image parts are not all uploaded is
        // auto-hidden — dropped from section play and from the quiz. The
        // quiz-draw pool is already servable-only, so this is a no-op there.
        return $this->questionQuery($section)->get()
            ->filter(static fn (Question $q): bool => Question::payloadServable($q->payload))
            ->values();
    }

    private function questionQuery(object $section)
    {
        $query = Question::query()
            ->join('exercise_sets as es', 'es.id', '=', 'questions.exercise_set_id')
            // FR-4.17 still gates the inner content too: a draft SET in
            // a published section stays invisible (FR-13.20 — imports
            // land as drafts and must not leak through here).
            ->where('es.status', ExerciseSet::STATUS_PUBLISHED)
            ->where('questions.is_active', 1)
            ->select([
                'questions.*',
                'es.type as exercise_type',
                'es.pair_mode',
                'es.input_mode',
            ]);

        if ($section->type === Section::TYPE_QUIZ) {
            $childId = (int) $section->unit_section_id;

            // FR-14.3 / §3: a child unit that set quiz targets serves ONE
            // fixed random draw (the same set for everyone), created lazily
            // and self-healed here. A unit with no targets keeps the old
            // all-eligible behaviour below.
            if ($this->quizDraw->hasTargets($childId)) {
                $drawIds = $this->quizDraw->ensure($childId);

                if ($drawIds === []) {
                    // Targeted but nothing drawable yet — serve nothing
                    // rather than falling back to the whole eligible pool.
                    return $query->whereRaw('1 = 0');
                }

                return $query
                    ->join('sections as sec', 'sec.id', '=', 'es.section_id')
                    ->where('sec.unit_section_id', $childId)
                    ->where('sec.status', Section::STATUS_PUBLISHED)
                    ->where('questions.quiz_eligible', 1)
                    ->whereIn('questions.id', $drawIds)
                    // Draw order (§3): vocabulary → grammar → listening, as
                    // stored in quiz_draws.
                    ->orderByRaw('FIELD(questions.id, ' . implode(',', array_map('intval', $drawIds)) . ')');
            }

            return $query
                ->join('sections as sec', 'sec.id', '=', 'es.section_id')
                ->where('sec.unit_section_id', $childId)
                ->where('sec.type', '!=', Section::TYPE_QUIZ)
                ->where('sec.status', Section::STATUS_PUBLISHED)
                ->where('questions.quiz_eligible', 1)
                // Source order (FR-13.4): section, then set, then the
                // authored question order — never shuffled.
                ->orderBy('sec.sort_order')
                ->orderBy('sec.id')
                ->orderBy('es.sort_order')
                ->orderBy('es.id')
                ->orderBy('questions.sort_order')
                ->orderBy('questions.id');
        }

        return $query
            ->where('es.section_id', (int) $section->id)
            ->orderBy('es.sort_order')
            ->orderBy('es.id')
            ->orderBy('questions.sort_order')
            ->orderBy('questions.id');
    }

    /** @return list<array<string, mixed>> newest attempt first */
    private function historyRows(User $student, int $sectionId): array
    {
        return Capsule::table('section_attempts')
            ->where('student_id', $student->id)
            ->where('section_id', $sectionId)
            ->orderByDesc('attempt_no')
            ->get()
            ->map(fn ($row): array => [
                'attempt_no'   => (int) $row->attempt_no,
                'percent'      => (float) $row->percent,
                'correct'      => (int) $row->correct_count,
                'total'        => (int) $row->question_count,
                'completed_at' => (string) $row->completed_at,
            ])->all();
    }

    private function incompleteAttempt(): ApiException
    {
        // FR-13.5: an attempt is all of the section's questions or
        // nothing at all.
        return ApiException::validation(
            'Ähli soraglara jogap beriň.',
            'Ответьте на все вопросы.'
        );
    }
}
