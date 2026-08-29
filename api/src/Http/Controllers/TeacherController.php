<?php

declare(strict_types=1);

namespace Dana\Http\Controllers;

use Dana\Domain\Models\Classroom;
use Dana\Domain\Models\ExerciseSet;
use Dana\Domain\Models\Section;
use Dana\Domain\Models\User;
use Dana\Domain\Progress\StatsService;
use Dana\Http\ApiException;
use Illuminate\Database\Capsule\Manager as Capsule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The teacher is READ-ONLY (FR-13.10): their classes, their students,
 * their students' results. No student creation, no unlocking (gone with
 * FR-13.3), no password authority (FR-13.17 — credentials live under
 * /manage, admin-only).
 *
 * Progress numbers are re-derived from section_attempts /
 * student_section_stats, never from anything the client asserts.
 */
final class TeacherController extends Controller
{
    public function __construct(
        private readonly StatsService $stats,
    ) {
    }

    /** FR-1.15: a teacher may own several classrooms. */
    public function classrooms(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $scope = $this->scope($request);
        $scope->requireRole(User::ROLE_TEACHER, User::ROLE_ADMIN, User::ROLE_SUPERADMIN);

        $rows = $scope->applyToClassrooms(Classroom::query())
            ->where('is_active', 1)
            ->with('level')
            ->get();

        return $this->json($response, [
            'classrooms' => $rows->map(fn (Classroom $c): array => [
                'id'            => (int) $c->id,
                'name'          => $c->name,
                'level'         => $c->level?->name,
                // The course card's "Mon, Wed, Fri | 12:00 - 14:35" line.
                'schedule'      => $c->schedule_note,
                'student_count' => $c->students()->where('is_active', 1)->count(),
                'closed'        => $c->isClosed(),
            ])->all(),
        ]);
    }

    /**
     * The class register with each student's average % — the FR-13.8
     * level correctness, the same number their leaderboard shows, so
     * teacher and student never argue about two different figures.
     */
    public function students(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $classroom = $this->classroom($request, (int) $args['id']);

        $students = $classroom->students()->where('is_active', 1)->get();

        $map = $this->stats->levelMap((int) $classroom->level_id);

        $stats = Capsule::table('student_section_stats')
            ->where('classroom_id', $classroom->id)
            ->get()
            ->groupBy('student_id');

        return $this->json($response, [
            'students' => $students->map(function (User $s) use ($map, $stats): array {
                $avgBySection = [];

                foreach ($stats[$s->id] ?? [] as $stat) {
                    $avgBySection[(int) $stat->section_id] =
                        (float) $stat->percent_sum / (int) $stat->attempts;
                }

                $attempted = count($avgBySection);

                return [
                    'id'                 => (int) $s->id,
                    'full_name'          => $s->full_name,
                    'login'              => $s->login,
                    'sections_attempted' => $attempted,
                    'average'            => $attempted === 0
                        ? null
                        : round(StatsService::correctness($map['by_child'], $avgBySection), 1),
                ];
            })->all(),
        ]);
    }

    /**
     * FR-12.10: one student's full attempt history, each attempt with
     * its per-question answers — which questions went wrong, not just
     * totals. A teacher planning the next lesson needs the items.
     */
    public function studentAttempts(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $scope->requireRole(User::ROLE_TEACHER, User::ROLE_ADMIN, User::ROLE_SUPERADMIN);

        // Scoped lookup: a teacher may only inspect students in their own
        // classrooms.
        $student = $scope->applyToUsers(User::query())
            ->where('users.id', (int) $args['id'])
            ->where('role', User::ROLE_STUDENT)
            ->first();

        if ($student === null) {
            throw ApiException::notFound();
        }

        $attempts = Capsule::table('section_attempts as a')
            ->join('sections as sec', 'sec.id', '=', 'a.section_id')
            ->join('unit_sections as cu', 'cu.id', '=', 'sec.unit_section_id')
            ->join('units as u', 'u.id', '=', 'cu.unit_id')
            ->where('a.student_id', $student->id)
            ->orderByDesc('a.completed_at')
            ->orderByDesc('a.id')
            ->select([
                'a.id', 'a.section_id', 'a.attempt_no', 'a.question_count',
                'a.correct_count', 'a.percent', 'a.completed_at',
                'sec.type as section_type', 'sec.title_tk', 'sec.title_ru',
                'cu.code', 'cu.label as cu_label', 'u.number as unit_number',
            ])
            ->get();

        $answers = Capsule::table('attempt_answers as ans')
            ->join('questions as q', 'q.id', '=', 'ans.question_id')
            ->join('exercise_sets as es', 'es.id', '=', 'q.exercise_set_id')
            ->whereIn('ans.attempt_id', $attempts->pluck('id')->all())
            ->orderBy('ans.sort_order')
            ->select([
                'ans.attempt_id', 'ans.given', 'ans.is_correct',
                'q.payload', 'es.type',
            ])
            ->get()
            ->groupBy('attempt_id');

        return $this->json($response, [
            'student' => [
                'id'        => (int) $student->id,
                'full_name' => $student->full_name,
                'login'     => $student->login,
            ],
            'attempts' => $attempts->map(function ($row) use ($answers): array {
                return [
                    'section_id'   => (int) $row->section_id,
                    'section_type' => $row->section_type,
                    // Explicit label wins verbatim (manual naming, 2026-08-20).
                    'label'        => $row->cu_label ?? ($row->unit_number . '-' . $row->code),
                    'title_tk'     => $row->title_tk,
                    'title_ru'     => $row->title_ru,
                    'attempt_no'   => (int) $row->attempt_no,
                    'percent'      => (float) $row->percent,
                    'correct'      => (int) $row->correct_count,
                    'total'        => (int) $row->question_count,
                    'completed_at' => (string) $row->completed_at,
                    'answers'      => collect($answers[$row->id] ?? [])->map(function ($a): array {
                        $payload = json_decode((string) $a->payload, true) ?? [];

                        return [
                            'type'     => $a->type,
                            'question' => self::describe((string) $a->type, $payload),
                            // The right answer, so the teacher can see
                            // the gap without opening the editor.
                            'answer'   => self::correctAnswer((string) $a->type, $payload),
                            'given'    => self::givenText(
                                (string) $a->type,
                                $payload,
                                json_decode((string) $a->given, true)
                            ),
                            'correct'  => (bool) $a->is_correct,
                        ];
                    })->values()->all(),
                ];
            })->all(),
        ]);
    }

    /**
     * The Student Detail screen (design `student-detail`): overall level
     * correctness, classroom rank, total active time and the per-child-
     * unit averages. Read-only, derived from the same aggregates as the
     * student's own numbers — teacher and student always see one figure.
     */
    public function studentOverview(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $scope->requireRole(User::ROLE_TEACHER, User::ROLE_ADMIN, User::ROLE_SUPERADMIN);

        $student = $scope->applyToUsers(User::query())
            ->where('users.id', (int) $args['id'])
            ->where('role', User::ROLE_STUDENT)
            ->first();

        if ($student === null || $student->classroom === null) {
            throw ApiException::notFound();
        }

        $classroom = $student->classroom;
        $map = $this->stats->levelMap((int) $classroom->level_id);

        $avgBySection = [];
        foreach (Capsule::table('student_section_stats')
            ->where('student_id', $student->id)
            ->get() as $stat) {
            $avgBySection[(int) $stat->section_id] =
                (float) $stat->percent_sum / (int) $stat->attempts;
        }

        // Child units in level order, each with this student's average
        // over its attemptable sections (FR-13.8 split).
        $children = Capsule::table('unit_sections as cu')
            ->join('units as u', 'u.id', '=', 'cu.unit_id')
            ->where('u.level_id', (int) $classroom->level_id)
            ->orderBy('u.sort_order')
            ->orderBy('cu.sort_order')
            ->select(['cu.id', 'cu.code', 'cu.label', 'cu.title', 'u.number'])
            ->get();

        $units = [];
        $completed = 0;
        $attemptable = 0;

        foreach ($children as $child) {
            $sectionIds = $map['by_child'][(int) $child->id] ?? [];
            $tried = count(array_intersect($sectionIds, array_keys($avgBySection)));

            $state = 'empty';
            if ($sectionIds !== []) {
                $attemptable++;
                $state = $tried === 0
                    ? 'not_started'
                    : ($tried === count($sectionIds) ? 'completed' : 'in_progress');
                if ($state === 'completed') {
                    $completed++;
                }
            }

            $sum = 0.0;
            foreach ($sectionIds as $sectionId) {
                $sum += $avgBySection[$sectionId] ?? 0.0;
            }

            $units[] = [
                'id'      => (int) $child->id,
                // Explicit label wins verbatim (manual naming, 2026-08-20).
                'label'   => $child->label ?? ($child->number . '-' . $child->code),
                'title'   => $child->title,
                'state'   => $state,
                'average' => $tried === 0
                    ? null
                    : round($sum / max(1, count($sectionIds)), 1),
            ];
        }

        // The classroom rank comes from the same ordering the students'
        // leaderboard uses (FR-13.19) — never re-derived differently.
        $rank = null;
        foreach ($this->stats->leaderboard($student, PHP_INT_MAX)['entries'] as $entry) {
            if ($entry['student_id'] === (int) $student->id) {
                $rank = $entry['rank'];
                break;
            }
        }

        $seconds = (int) (Capsule::table('student_activity_days')
            ->where('student_id', $student->id)
            ->sum('seconds_studied'));

        return $this->json($response, [
            'student' => [
                'id'        => (int) $student->id,
                'full_name' => $student->full_name,
                'login'     => $student->login,
            ],
            'level'           => $classroom->level?->name,
            'percent'         => (int) round(StatsService::correctness($map['by_child'], $avgBySection)),
            'units_completed' => $completed,
            'units_total'     => $attemptable,
            'rank'            => $rank,
            'study_seconds'   => $seconds,
            'units'           => $units,
        ]);
    }

    /**
     * The teacher "Unit progress" screen (design `unit-vocabulary-screen-3`):
     * one student's per-module results for one child unit — Grammar /
     * Listening / Vocabulary with their try counts and averages, the Exam
     * Quiz on its own, and the unit's overall %. The overall is computed
     * with the SAME equal-split as the Student Detail unit row so the two
     * screens never show different numbers for the same unit.
     */
    public function studentUnitProgress(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $scope->requireRole(User::ROLE_TEACHER, User::ROLE_ADMIN, User::ROLE_SUPERADMIN);

        $student = $scope->applyToUsers(User::query())
            ->where('users.id', (int) $args['id'])
            ->where('role', User::ROLE_STUDENT)
            ->first();

        if ($student === null || $student->classroom === null) {
            throw ApiException::notFound();
        }

        $classroom = $student->classroom;
        $childUnitId = (int) $args['childUnitId'];

        // The child unit, scoped to the student's own level.
        $child = Capsule::table('unit_sections as cu')
            ->join('units as u', 'u.id', '=', 'cu.unit_id')
            ->where('cu.id', $childUnitId)
            ->where('u.level_id', (int) $classroom->level_id)
            ->select(['cu.id', 'cu.code', 'cu.label', 'cu.title', 'u.number'])
            ->first();

        if ($child === null) {
            throw ApiException::notFound();
        }

        // Published sections of the child unit, in author order.
        $sections = Capsule::table('sections')
            ->where('unit_section_id', $childUnitId)
            ->where('status', Section::STATUS_PUBLISHED)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $stats = Capsule::table('student_section_stats')
            ->where('student_id', $student->id)
            ->whereIn('section_id', $sections->pluck('id')->all())
            ->get()
            ->keyBy('section_id');

        $avgBySection = [];
        foreach ($stats as $sectionId => $stat) {
            $avgBySection[(int) $sectionId] =
                (float) $stat->percent_sum / (int) $stat->attempts;
        }

        $modules = [];
        $quiz = null;

        foreach ($sections as $sec) {
            $id = (int) $sec->id;
            $tries = isset($stats[$id]) ? (int) $stats[$id]->attempts : 0;
            $average = isset($avgBySection[$id]) ? (int) round($avgBySection[$id]) : null;

            if ($sec->type === Section::TYPE_QUIZ) {
                // FR-13.4: one quiz per child unit; the last one wins if a
                // legacy unit somehow carries two.
                $quiz = [
                    'section_id' => $id,
                    'tries'      => $tries,
                    'average'    => $average,
                ];
                continue;
            }

            $modules[] = [
                'section_id' => $id,
                'type'       => $sec->type,
                'title_tk'   => $sec->title_tk,
                'title_ru'   => $sec->title_ru,
                'tries'      => $tries,
                'average'    => $average,
            ];
        }

        // UNIT OVERALL — identical formula to studentOverview()'s unit row
        // (FR-13.8 equal split over the unit's attemptable sections).
        $map = $this->stats->levelMap((int) $classroom->level_id);
        $sectionIds = $map['by_child'][$childUnitId] ?? [];
        $tried = count(array_intersect($sectionIds, array_keys($avgBySection)));

        $sum = 0.0;
        foreach ($sectionIds as $sectionId) {
            $sum += $avgBySection[$sectionId] ?? 0.0;
        }

        return $this->json($response, [
            'unit' => [
                // Explicit label wins verbatim (manual naming, 2026-08-20).
                'label'   => $child->label ?? ($child->number . '-' . $child->code),
                'title'   => $child->title,
                'average' => $tried === 0 ? null : (int) round($sum / max(1, count($sectionIds))),
            ],
            'modules' => $modules,
            'quiz'    => $quiz,
        ]);
    }

    // The teacher's section-grammar read went with the grammar guide
    // (FR-13.26, 2026-08-13).

    /** A unit's published vocabulary for the teacher's unit screen. */
    public function unitVocabulary(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $scope = $this->scope($request);
        $scope->requireRole(User::ROLE_TEACHER, User::ROLE_ADMIN, User::ROLE_SUPERADMIN);

        // Scoped through the classrooms the caller may see: the unit must
        // belong to a level they actually teach.
        $teachable = $scope->applyToClassrooms(Classroom::query())
            ->where('is_active', 1)
            ->pluck('level_id')
            ->all();

        $unit = Capsule::table('units')
            ->where('id', (int) $args['id'])
            ->whereIn('level_id', $teachable)
            ->first();

        if ($unit === null) {
            throw ApiException::notFound();
        }

        $items = Capsule::table('vocabulary_items as v')
            ->join('sections as sec', 'sec.id', '=', 'v.section_id')
            ->join('unit_sections as cu', 'cu.id', '=', 'sec.unit_section_id')
            ->where('cu.unit_id', $unit->id)
            ->where('sec.status', Section::STATUS_PUBLISHED)
            ->orderBy('v.term_en')
            ->select([
                'v.id', 'v.term_en', 'v.part_of_speech', 'v.ipa',
                'v.translation_tk', 'v.translation_ru', 'v.example_en',
                'v.audio_path', 'v.category', 'v.word_type', 'cu.code',
                'cu.label as cu_label',
            ])
            ->get();

        return $this->json($response, [
            'items' => $items->map(fn ($row): array => [
                'id'             => (int) $row->id,
                'term_en'        => $row->term_en,
                'part_of_speech' => $row->part_of_speech,
                'ipa'            => $row->ipa,
                'translation_tk' => $row->translation_tk,
                'translation_ru' => $row->translation_ru,
                'example_en'     => $row->example_en,
                'audio_path'     => $row->audio_path,
                // Wordlist v2's Category and Type (FR-14.6/FR-15.6).
                'category'       => $row->category,
                'word_type'      => $row->word_type,
                // Explicit label wins verbatim (manual naming, 2026-08-20).
                'label'          => $row->cu_label ?? ($unit->number . '-' . $row->code),
                // Teachers have no bookmarks; the screen hides the toggle.
                'bookmarked'     => false,
            ])->all(),
        ]);
    }

    // Password reveal/reset used to live here. FR-13.17 removed the
    // teacher's credential authority entirely — those routes are now
    // /manage/students/{id}/credential|password, admin-only.

    /**
     * The readable text of a stem or option that may be a v2 part object
     * (`{text}` / `{audio_note}` / `{image_note}`, §2) or a legacy bare
     * string. Casting the object with (string) printed the literal word
     * "Array" all over the teacher's review — a media part now names its
     * note instead ("аудио: «seven»"), which is what the panel shows too.
     */
    private static function partText(mixed $part): string
    {
        if (is_array($part)) {
            if (($part['text'] ?? '') !== '') {
                return (string) $part['text'];
            }

            if (($part['audio_note'] ?? '') !== '') {
                return 'аудио: «' . $part['audio_note'] . '»';
            }

            if (($part['image_note'] ?? '') !== '') {
                return 'фото: «' . $part['image_note'] . '»';
            }

            return '';
        }

        return (string) ($part ?? '');
    }

    private static function describe(string $type, array $payload): string
    {
        return match ($type) {
            ExerciseSet::TYPE_MULTIPLE_CHOICE => self::partText($payload['stem'] ?? ''),
            ExerciseSet::TYPE_FILL_BLANK      => trim(($payload['before'] ?? '') . ' ___ ' . ($payload['after'] ?? '')),
            ExerciseSet::TYPE_REORDER         => implode(' ', $payload['tokens'] ?? []),
            ExerciseSet::TYPE_MATCH_PAIRS     => count($payload['pairs'] ?? []) . ' pairs',
            // The sentence with the hidden letters blanked out, e.g.
            // "I just wa__ to go." — enough for the teacher to place it.
            ExerciseSet::TYPE_FILL_LETTER_SPACE => self::letterSpaceText($payload, true),
            default                             => '',
        };
    }

    private static function correctAnswer(string $type, array $payload): string
    {
        return match ($type) {
            ExerciseSet::TYPE_MULTIPLE_CHOICE => self::partText(($payload['options'] ?? [])[$payload['answer'] ?? 0] ?? ''),
            ExerciseSet::TYPE_FILL_BLANK      => (string) (($payload['answer'] ?? [])[0] ?? ''),
            ExerciseSet::TYPE_REORDER         => implode(' ', $payload['tokens'] ?? []),
            ExerciseSet::TYPE_MATCH_PAIRS     => implode(', ', array_map(
                static fn (array $p): string => ($p['left'] ?? '') . ' = ' . ($p['right'] ?? $p['right_ru'] ?? ''),
                $payload['pairs'] ?? []
            )),
            ExerciseSet::TYPE_FILL_LETTER_SPACE => self::letterSpaceText($payload, false),
            default                             => '',
        };
    }

    /**
     * What the student actually chose, as text. For multiple choice the
     * wire answer is the option's ORIGINAL index (FR-14.4) — the teacher
     * saw «✗ 2» where the chosen option's words belong. Other types
     * already travel as words; lists are joined for display.
     */
    private static function givenText(string $type, array $payload, mixed $given): mixed
    {
        if ($type === ExerciseSet::TYPE_MULTIPLE_CHOICE && (is_int($given) || ctype_digit((string) $given))) {
            return self::partText(($payload['options'] ?? [])[(int) $given] ?? '');
        }

        if (is_array($given) && array_is_list($given) && $given !== []
            && array_filter($given, static fn ($v): bool => !is_scalar($v)) === []) {
            return implode(' ', array_map(strval(...), $given));
        }

        return $given;
    }

    /** The authored sentence, blanks either masked or spelled out. */
    private static function letterSpaceText(array $payload, bool $masked): string
    {
        $reveal = max(0, (int) ($payload['reveal'] ?? 0));

        return (string) preg_replace_callback(
            '/\{([^{}]+)\}/u',
            static function (array $m) use ($reveal, $masked): string {
                if (!$masked) {
                    return $m[1];
                }

                $shown = min($reveal, mb_strlen($m[1]));

                return mb_substr($m[1], 0, $shown)
                    . str_repeat('_', mb_strlen($m[1]) - $shown);
            },
            (string) ($payload['text'] ?? '')
        );
    }

    private function classroom(ServerRequestInterface $request, int $id): Classroom
    {
        $scope = $this->scope($request);
        $scope->requireRole(User::ROLE_TEACHER, User::ROLE_ADMIN, User::ROLE_SUPERADMIN);

        $classroom = $scope->applyToClassrooms(Classroom::query())->where('id', $id)->first();

        if ($classroom === null) {
            throw ApiException::notFound();
        }

        return $classroom;
    }
}
