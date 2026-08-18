<?php

declare(strict_types=1);

namespace Dana\Domain\Progress;

use Dana\Domain\Models\ExerciseSet;
use Dana\Domain\Models\Question;
use Dana\Domain\Models\Section;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * The child unit's FIXED quiz draw (docs/06-CONTENT-V2.md §3, FR-14.3).
 *
 * When a child unit sets any `quiz_target_*`, its Exam Quiz is not the
 * whole eligible pool but ONE fixed random draw of
 * `quiz_target_vocabulary` + `quiz_target_grammar` + `quiz_target_listening`
 * SERVABLE eligible questions, stored in `quiz_draws`, grouped
 * vocabulary → grammar → listening. Every student gets the same set.
 *
 *  - The draw is created LAZILY on first serve.
 *  - It SELF-HEALS: a drawn question that has stopped being servable
 *    (its media was cleared, its section unpublished, it was deleted) is
 *    dropped and replaced from its skill pool; still-valid picks are kept
 *    stable so the quiz does not reshuffle for no reason.
 *  - A pool smaller than its target contributes the whole pool.
 *  - A skill with no target contributes nothing to a targeted draw.
 *  - The superadmin can force a fresh draw (redraw()).
 *
 * A child unit with NO targets is not managed here at all — the caller
 * keeps the old all-eligible quiz behaviour.
 *
 * This never reads or writes student tables; `quiz_draws` is a cache of a
 * random choice over published content.
 */
final class QuizDrawService
{
    /** Draw order: vocabulary first, then grammar, then listening (§3). */
    public const SKILLS = [
        Section::TYPE_VOCABULARY,
        Section::TYPE_GRAMMAR,
        Section::TYPE_LISTENING,
    ];

    /** Any `quiz_target_*` set means the quiz is a fixed draw (§3). */
    public function hasTargets(int $childUnitId): bool
    {
        $t = $this->targets($childUnitId);

        return $t['vocabulary'] !== null || $t['grammar'] !== null || $t['listening'] !== null;
    }

    /** @return array{vocabulary: ?int, grammar: ?int, listening: ?int} */
    public function targets(int $childUnitId): array
    {
        $row = Capsule::table('unit_sections')->where('id', $childUnitId)->first([
            'quiz_target_vocabulary', 'quiz_target_grammar', 'quiz_target_listening',
        ]);

        $read = static fn (mixed $v): ?int => $v === null ? null : (int) $v;

        return [
            'vocabulary' => $read($row->quiz_target_vocabulary ?? null),
            'grammar'    => $read($row->quiz_target_grammar ?? null),
            'listening'  => $read($row->quiz_target_listening ?? null),
        ];
    }

    /**
     * The draw pool for one skill: servable, eligible, active questions of
     * that child unit's section of that type, from published sections and
     * published sets, in a stable order (selection from it is random).
     *
     * @return list<int> question ids
     */
    public function pool(int $childUnitId, string $skill): array
    {
        $rows = Capsule::table('questions as q')
            ->join('exercise_sets as es', 'es.id', '=', 'q.exercise_set_id')
            ->join('sections as s', 's.id', '=', 'es.section_id')
            ->where('s.unit_section_id', $childUnitId)
            ->where('s.type', $skill)
            ->where('s.status', Section::STATUS_PUBLISHED)
            ->where('es.status', ExerciseSet::STATUS_PUBLISHED)
            ->where('q.is_active', 1)
            ->where('q.quiz_eligible', 1)
            ->orderBy('s.sort_order')->orderBy('s.id')
            ->orderBy('es.sort_order')->orderBy('es.id')
            ->orderBy('q.sort_order')->orderBy('q.id')
            ->get(['q.id', 'q.payload']);

        $ids = [];

        foreach ($rows as $row) {
            // §3: a question with a pending (un-uploaded) media part is not
            // servable and never enters a quiz pool.
            if (Question::payloadServable(json_decode((string) $row->payload, true))) {
                $ids[] = (int) $row->id;
            }
        }

        return $ids;
    }

    /**
     * The size the draw will have per skill = min(target, pool). A null
     * target contributes 0. Lets a count be shown without creating a draw
     * (StatsService reuses the same arithmetic for the quiz denominator).
     *
     * @return array{vocabulary: int, grammar: int, listening: int, total: int}
     */
    public function plannedCounts(int $childUnitId): array
    {
        $targets = $this->targets($childUnitId);
        $out = ['total' => 0];

        foreach (self::SKILLS as $skill) {
            $target = $targets[$skill];
            $size = $target === null ? 0 : min($target, count($this->pool($childUnitId, $skill)));
            $out[$skill] = $size;
            $out['total'] += $size;
        }

        return $out;
    }

    /**
     * The ordered question ids the quiz serves for a targeted child unit,
     * creating the draw on first call and self-healing it thereafter. The
     * stored draw is rewritten only when it actually changes, so two
     * serves — and two students — see the same set.
     *
     * @return list<int> ordered vocabulary → grammar → listening
     */
    public function ensure(int $childUnitId): array
    {
        return Capsule::connection()->transaction(function () use ($childUnitId): array {
            // Serialise concurrent first-serves so two students cannot each
            // create a different draw.
            Capsule::table('unit_sections')->where('id', $childUnitId)->lockForUpdate()->first();

            $storedIds = array_map(
                static fn ($r): int => (int) $r->question_id,
                Capsule::table('quiz_draws')
                    ->where('unit_section_id', $childUnitId)
                    ->orderBy('sort_order')->orderBy('question_id')
                    ->get(['question_id'])->all()
            );

            $skillOf = $this->skillOf($storedIds);
            $targets = $this->targets($childUnitId);
            $ordered = [];

            foreach (self::SKILLS as $skill) {
                $pool = $this->pool($childUnitId, $skill);
                $poolSet = array_flip($pool);
                $target = $targets[$skill];
                $desired = $target === null ? 0 : min($target, count($pool));

                // Keep still-servable stored picks of this skill (stable).
                $kept = [];

                foreach ($storedIds as $id) {
                    if (($skillOf[$id] ?? null) === $skill && isset($poolSet[$id])) {
                        $kept[] = $id;
                    }
                }

                if (count($kept) > $desired) {
                    $kept = array_slice($kept, 0, $desired);
                } elseif (count($kept) < $desired) {
                    // Backfill from the rest of the pool, at random.
                    $keptSet = array_flip($kept);
                    $candidates = array_values(array_filter(
                        $pool,
                        static fn (int $id): bool => !isset($keptSet[$id])
                    ));
                    shuffle($candidates);
                    $kept = array_merge($kept, array_slice($candidates, 0, $desired - count($kept)));
                }

                foreach ($kept as $id) {
                    $ordered[] = $id;
                }
            }

            // Rewrite only on a real change — no churn for a healthy draw.
            if ($ordered !== $storedIds) {
                Capsule::table('quiz_draws')->where('unit_section_id', $childUnitId)->delete();

                $rows = [];

                foreach ($ordered as $i => $id) {
                    $rows[] = [
                        'unit_section_id' => $childUnitId,
                        'question_id'     => $id,
                        'sort_order'      => $i + 1,
                    ];
                }

                if ($rows !== []) {
                    Capsule::table('quiz_draws')->insert($rows);
                }
            }

            return $ordered;
        });
    }

    /**
     * Clears the stored draw and makes a fresh one (superadmin redraw).
     *
     * @return array{
     *   unit_section_id: int,
     *   question_ids: list<int>,
     *   external_codes: list<string>,
     *   counts: array{vocabulary: int, grammar: int, listening: int},
     *   total: int
     * }
     */
    public function redraw(int $childUnitId): array
    {
        Capsule::table('quiz_draws')->where('unit_section_id', $childUnitId)->delete();

        $ids = $this->ensure($childUnitId);

        $rows = $ids === []
            ? collect()
            : Capsule::table('questions as q')
                ->join('exercise_sets as es', 'es.id', '=', 'q.exercise_set_id')
                ->join('sections as s', 's.id', '=', 'es.section_id')
                ->whereIn('q.id', $ids)
                ->get(['q.id', 'q.external_code', 's.type'])
                ->keyBy('id');

        $counts = ['vocabulary' => 0, 'grammar' => 0, 'listening' => 0];
        $codes = [];

        foreach ($ids as $id) {
            $row = $rows[$id] ?? null;
            $codes[] = (string) ($row->external_code ?? '');

            if ($row !== null && isset($counts[(string) $row->type])) {
                $counts[(string) $row->type]++;
            }
        }

        return [
            'unit_section_id' => $childUnitId,
            'question_ids'    => $ids,
            'external_codes'  => $codes,
            'counts'          => $counts,
            'total'           => count($ids),
        ];
    }

    /**
     * The skill (section type) of each question id.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    private function skillOf(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = Capsule::table('questions as q')
            ->join('exercise_sets as es', 'es.id', '=', 'q.exercise_set_id')
            ->join('sections as s', 's.id', '=', 'es.section_id')
            ->whereIn('q.id', $ids)
            ->get(['q.id', 's.type']);

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->id] = (string) $row->type;
        }

        return $out;
    }
}
