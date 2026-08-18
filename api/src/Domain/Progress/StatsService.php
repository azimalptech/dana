<?php

declare(strict_types=1);

namespace Dana\Domain\Progress;

use Dana\Domain\Models\ExerciseSet;
use Dana\Domain\Models\Question;
use Dana\Domain\Models\Section;
use Dana\Domain\Models\User;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * Leaderboard, streak and study time — the §13 numbers.
 *
 * The point system is gone (FR-13.7): the only measure anywhere is the
 * percentage of correctly answered questions, weighted equally at every
 * layer (FR-13.8) — a section's 100% splits across its questions, a
 * child unit's across its sections, a level's across its child units.
 *
 * Everything is computed server-side from stored facts. The client
 * reports elapsed foreground seconds and nothing else — it never asserts
 * a rank, a score or a streak length.
 */
final class StatsService
{
    /**
     * A single heartbeat may not claim more than this. The app sends one
     * every 30s; anything larger is either a bug or a client trying to
     * inflate its study time, and neither should be trusted.
     */
    private const MAX_HEARTBEAT_SECONDS = 120;

    /** Nobody studies 12 hours in a day. Caps runaway accumulation. */
    private const MAX_DAILY_SECONDS = 43200;

    /**
     * The level's attemptable structure: which published sections carry
     * questions, grouped by child unit — the denominators of every
     * FR-13.8 split.
     *
     * A section with nothing to answer (a Vocabulary section holding
     * only words, a quiz whose siblings flagged no questions eligible)
     * cannot be attempted, so it carries no weight: counting it would
     * cap everyone below 100% forever. Child units with no attemptable
     * sections drop out of the level split for the same reason.
     *
     * @return array{
     *   counts: array<int, int>,        // published section id => question count
     *   by_child: array<int, list<int>> // child unit id => attemptable section ids
     * }
     */
    public function levelMap(int $levelId): array
    {
        $sections = Capsule::table('sections as sec')
            ->join('unit_sections as cu', 'cu.id', '=', 'sec.unit_section_id')
            ->join('units as u', 'u.id', '=', 'cu.unit_id')
            ->where('u.level_id', $levelId)
            ->where('sec.status', Section::STATUS_PUBLISHED)
            ->orderBy('u.sort_order')
            ->orderBy('cu.sort_order')
            ->orderBy('sec.sort_order')
            ->orderBy('sec.id')
            ->select(['sec.id', 'sec.unit_section_id', 'sec.type'])
            ->get();

        // One pass over the level's questions, carrying each payload so
        // servability (§3) can be judged in PHP — a question with a pending
        // (un-uploaded) media part is auto-hidden and must not be counted,
        // in section play or in any percent denominator.
        $questionRows = Capsule::table('questions as q')
            ->join('exercise_sets as es', 'es.id', '=', 'q.exercise_set_id')
            ->join('sections as sec', 'sec.id', '=', 'es.section_id')
            ->join('unit_sections as cu', 'cu.id', '=', 'sec.unit_section_id')
            ->join('units as u', 'u.id', '=', 'cu.unit_id')
            ->where('u.level_id', $levelId)
            ->where('sec.status', Section::STATUS_PUBLISHED)
            ->where('es.status', ExerciseSet::STATUS_PUBLISHED)
            ->where('q.is_active', 1)
            ->get(['q.payload', 'q.quiz_eligible', 'sec.id as section_id',
                   'sec.unit_section_id as child_id', 'sec.type as section_type']);

        $own = [];                 // section id => servable question count
        $eligibleByChildSkill = []; // child id => [skill => servable eligible count]

        foreach ($questionRows as $row) {
            if (!Question::payloadServable(json_decode((string) $row->payload, true))) {
                continue;
            }

            $sectionId = (int) $row->section_id;
            $own[$sectionId] = ($own[$sectionId] ?? 0) + 1;

            if ((int) $row->quiz_eligible === 1) {
                $childId = (int) $row->child_id;
                $skill = (string) $row->section_type;
                $eligibleByChildSkill[$childId][$skill] =
                    ($eligibleByChildSkill[$childId][$skill] ?? 0) + 1;
            }
        }

        // Quiz targets per child unit — a targeted quiz's size is the fixed
        // draw's size (min(target, pool) per skill), not the whole pool.
        $targets = Capsule::table('unit_sections as cu')
            ->join('units as u', 'u.id', '=', 'cu.unit_id')
            ->where('u.level_id', $levelId)
            ->get(['cu.id', 'cu.quiz_target_vocabulary', 'cu.quiz_target_grammar', 'cu.quiz_target_listening'])
            ->keyBy('id');

        $counts = [];
        $byChild = [];

        foreach ($sections as $section) {
            $id = (int) $section->id;
            $childId = (int) $section->unit_section_id;

            $counts[$id] = $section->type === Section::TYPE_QUIZ
                ? $this->quizSize($eligibleByChildSkill[$childId] ?? [], $targets[$childId] ?? null)
                : (int) ($own[$id] ?? 0);

            if ($counts[$id] > 0) {
                $byChild[$childId][] = $id;
            }
        }

        return ['counts' => $counts, 'by_child' => $byChild];
    }

    /**
     * The Exam Quiz's question count for the FR-13.8 denominator (§3):
     *
     *  - no targets set → the whole servable-eligible pool (old behaviour);
     *  - any target set → the fixed draw's size, min(target, pool) summed
     *    over the three skills, a null target contributing nothing.
     *
     * This mirrors QuizDrawService::plannedCounts exactly, without creating
     * a draw — levelMap is a read and must not write.
     *
     * @param array<string, int> $eligibleBySkill servable eligible count per section type
     */
    private function quizSize(array $eligibleBySkill, ?object $targets): int
    {
        $target = static fn (string $column): ?int =>
            $targets !== null && $targets->{$column} !== null ? (int) $targets->{$column} : null;

        $tv = $target('quiz_target_vocabulary');
        $tg = $target('quiz_target_grammar');
        $tl = $target('quiz_target_listening');

        $ev = (int) ($eligibleBySkill[Section::TYPE_VOCABULARY] ?? 0);
        $eg = (int) ($eligibleBySkill[Section::TYPE_GRAMMAR] ?? 0);
        $el = (int) ($eligibleBySkill[Section::TYPE_LISTENING] ?? 0);

        if ($tv === null && $tg === null && $tl === null) {
            return $ev + $eg + $el;
        }

        return ($tv === null ? 0 : min($tv, $ev))
            + ($tg === null ? 0 : min($tg, $eg))
            + ($tl === null ? 0 : min($tl, $el));
    }

    /**
     * FR-13.8: the equal-split average. Unattempted sections weigh in at
     * zero — the split is over the content, not over what the student
     * chose to open.
     *
     * @param array<int, list<int>> $byChild      child unit id => attemptable section ids
     * @param array<int, float>     $avgBySection student's per-section attempt averages
     * @return float 0–100
     */
    public static function correctness(array $byChild, array $avgBySection): float
    {
        if ($byChild === []) {
            return 0.0;
        }

        $sum = 0.0;

        foreach ($byChild as $sectionIds) {
            $child = 0.0;

            foreach ($sectionIds as $sectionId) {
                $child += $avgBySection[$sectionId] ?? 0.0;
            }

            $sum += $child / count($sectionIds);
        }

        return $sum / count($byChild);
    }

    /**
     * FR-13.9 / FR-13.19: leaderboard pool is the classroom; the score
     * is the level correctness percentage × 100 (0–10 000), so small
     * gaps read as meaningful. Reads the maintained aggregate
     * (student_section_stats), never the attempt log (NFR-2).
     */
    public function leaderboard(User $student, int $limit = 50): array
    {
        $classroom = $student->classroom;
        $map = $classroom === null ? ['by_child' => []] : $this->levelMap((int) $classroom->level_id);

        $students = Capsule::table('users')
            ->where('classroom_id', $student->classroom_id)
            ->where('role', User::ROLE_STUDENT)
            ->where('is_active', 1)
            ->select(['id', 'full_name'])
            ->get();

        $stats = Capsule::table('student_section_stats')
            ->where('classroom_id', $student->classroom_id)
            ->get()
            ->groupBy('student_id');

        $scored = [];

        foreach ($students as $row) {
            $avgBySection = [];
            $reachedAt = null;

            foreach ($stats[$row->id] ?? [] as $stat) {
                $avgBySection[(int) $stat->section_id] = (float) $stat->percent_sum / (int) $stat->attempts;

                // The score last changed at the newest completion — that
                // is when this student "reached" their current number.
                if ($reachedAt === null || $stat->last_completed_at > $reachedAt) {
                    $reachedAt = (string) $stat->last_completed_at;
                }
            }

            $scored[] = [
                'student_id'   => (int) $row->id,
                'display_name' => $this->displayName((string) $row->full_name),
                'score'        => (int) round(self::correctness($map['by_child'], $avgBySection) * 100),
                'reached_at'   => $reachedAt,
            ];
        }

        // FR-13.19: equal scores rank by who reached theirs first. A
        // student who never completed anything has no "reached" moment
        // and sorts after those who did.
        usort($scored, static function (array $a, array $b): int {
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            if ($a['reached_at'] !== $b['reached_at']) {
                return ($a['reached_at'] ?? '9999') <=> ($b['reached_at'] ?? '9999');
            }

            return $a['student_id'] <=> $b['student_id'];
        });

        $entries = [];
        $me = null;

        foreach ($scored as $i => $row) {
            $isMe = $row['student_id'] === (int) $student->id;

            $entry = [
                'rank'         => $i + 1,
                'student_id'   => $row['student_id'],
                'display_name' => $row['display_name'],
                'score'        => $row['score'],
                'is_me'        => $isMe,
            ];

            if ($isMe) {
                $me = $entry;
            }

            if ($i < $limit) {
                $entries[] = $entry;
            }
        }

        return [
            'classroom_id' => (int) $student->classroom_id,
            'entries'      => $entries,
            // Pinned separately so the app can always show the student's
            // own row even when they are below the visible window.
            'me'           => $me ?? [
                'rank'         => null,
                'student_id'   => (int) $student->id,
                'display_name' => $this->displayName($student->full_name),
                'score'        => 0,
                'is_me'        => true,
            ],
        ];
    }

    /** Profile screen: streak and study time. No points — FR-13.7. */
    public function forStudent(User $student): array
    {
        $totals = Capsule::table('student_activity_days')
            ->where('student_id', $student->id)
            ->selectRaw('COALESCE(SUM(seconds_studied),0) AS seconds, COALESCE(SUM(exercises_completed),0) AS done')
            ->first();

        return [
            'sections_completed' => (int) ($totals->done ?? 0),
            'study_seconds'      => (int) ($totals->seconds ?? 0),
            'streak_days'        => $this->streak($student),
        ];
    }

    /**
     * FR-8.10 (still in force): consecutive days with at least one
     * completed section attempt.
     *
     * The day boundary is Asia/Ashgabat, evaluated here on the server —
     * the device clock has no say, so travelling or changing the phone's
     * date cannot extend a streak.
     *
     * A streak stays alive if the last active day was today OR yesterday;
     * otherwise it has been broken and reads zero.
     */
    public function streak(User $student): int
    {
        $days = Capsule::table('student_activity_days')
            ->where('student_id', $student->id)
            ->where('exercises_completed', '>', 0)
            ->orderByDesc('activity_date')
            ->pluck('activity_date')
            ->all();

        if ($days === []) {
            return 0;
        }

        $today = new \DateTimeImmutable('today');
        $latest = new \DateTimeImmutable(substr((string) $days[0], 0, 10));
        $gap = (int) $today->diff($latest)->days;

        if ($gap > 1) {
            return 0;
        }

        $streak = 1;
        $previous = $latest;

        foreach (array_slice($days, 1) as $day) {
            $current = new \DateTimeImmutable(substr((string) $day, 0, 10));

            if ((int) $current->diff($previous)->days !== 1) {
                break;
            }

            $streak++;
            $previous = $current;
        }

        return $streak;
    }

    /** @return int total seconds recorded today, after clamping */
    public function recordStudyTime(User $student, int $seconds): int
    {
        $seconds = max(0, min($seconds, self::MAX_HEARTBEAT_SECONDS));
        $today = date('Y-m-d');
        $now = time();

        $row = Capsule::table('student_activity_days')
            ->where('student_id', $student->id)
            ->where('activity_date', $today)
            ->first();

        // Credit no more than the wall-clock time actually elapsed since
        // the last heartbeat. A client that loops the endpoint claiming
        // 120s each call now banks only the real gap between calls, so a
        // full day can no longer be forged in seconds. The first
        // heartbeat of the day (no prior mark) is trusted up to the cap.
        if ($row !== null && $row->last_heartbeat_at !== null) {
            $elapsed = $now - strtotime((string) $row->last_heartbeat_at);
            $seconds = max(0, min($seconds, $elapsed));
        }

        Capsule::connection()->statement(
            'INSERT INTO student_activity_days
                (student_id, activity_date, seconds_studied, last_heartbeat_at, exercises_completed)
             VALUES (?, ?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE
                seconds_studied = LEAST(seconds_studied + VALUES(seconds_studied), ?),
                last_heartbeat_at = VALUES(last_heartbeat_at)',
            [$student->id, $today, $seconds, date('Y-m-d H:i:s', $now), self::MAX_DAILY_SECONDS]
        );

        $updated = Capsule::table('student_activity_days')
            ->where('student_id', $student->id)
            ->where('activity_date', $today)
            ->first();

        return (int) ($updated->seconds_studied ?? 0);
    }

    /** FR-12.8: first name plus last initial, not the full name. */
    public function displayName(string $fullName): string
    {
        $parts = preg_split('/\s+/u', trim($fullName)) ?: [];
        $first = $parts[0] ?? $fullName;

        if (count($parts) < 2) {
            return $first;
        }

        return $first . ' ' . mb_strtoupper(mb_substr((string) end($parts), 0, 1)) . '.';
    }
}
