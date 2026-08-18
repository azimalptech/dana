<?php

declare(strict_types=1);

namespace Dana\Domain\Content;

use Dana\Domain\Models\Section;
use Dana\Domain\Models\User;
use Dana\Domain\Progress\StatsService;
use Dana\Http\ApiException;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * What a student is allowed to read.
 *
 * §13: everything is unlocked (FR-13.3) — there is no gating, no lock
 * state, no unlock filtering anywhere. Exactly ONE filter applies to
 * every query here, at this layer rather than in controllers so it can
 * never be forgotten:
 *
 *   FR-4.17 / FR-13.20  students read `sections.status = 'published'`
 *                       only, within their classroom's level.
 *
 * A draft section does not appear at all — not greyed out, not
 * returned-and-hidden. The server simply does not send it.
 */
final class ContentRepository
{
    public function __construct(
        private readonly StatsService $stats,
    ) {
    }

    /**
     * GET /me/outline — the whole level as the student sees it:
     * level → parent units → child units → typed sections, with the
     * student's own attempt counts and averages on every section.
     */
    public function outlineFor(User $student): array
    {
        $classroom = $student->classroom;

        if ($classroom === null) {
            throw ApiException::forbidden();
        }

        $levelId = (int) $classroom->level_id;

        $units = Capsule::table('units')
            ->where('level_id', $levelId)
            ->orderBy('sort_order')
            ->get();

        $childUnits = Capsule::table('unit_sections')
            ->whereIn('unit_id', $units->pluck('id')->all())
            ->orderBy('sort_order')
            ->get()
            ->groupBy('unit_id');

        $sections = Capsule::table('sections as sec')
            ->join('unit_sections as cu', 'cu.id', '=', 'sec.unit_section_id')
            ->join('units as u', 'u.id', '=', 'cu.unit_id')
            ->where('u.level_id', $levelId)
            ->where('sec.status', Section::STATUS_PUBLISHED)
            ->orderBy('sec.sort_order')
            ->orderBy('sec.id')
            ->select('sec.*')
            ->get()
            ->groupBy('unit_section_id');

        // Question counts + the FR-13.8 denominators, shared with the
        // leaderboard so the outline's score and the ranking screen can
        // never disagree.
        $map = $this->stats->levelMap($levelId);

        $mine = Capsule::table('student_section_stats')
            ->where('student_id', $student->id)
            ->get()
            ->keyBy('section_id');

        $parentUnits = [];
        $avgBySection = [];
        $attemptableTotal = 0;
        $attemptedTotal = 0;

        foreach ($units as $unit) {
            $outChildren = [];

            foreach ($childUnits[$unit->id] ?? [] as $child) {
                $outSections = [];
                $attemptable = $map['by_child'][(int) $child->id] ?? [];
                $attemptedHere = 0;

                foreach ($sections[$child->id] ?? [] as $section) {
                    $stat = $mine[$section->id] ?? null;
                    $attempts = (int) ($stat->attempts ?? 0);
                    $average = $attempts === 0
                        ? null
                        : round((float) $stat->percent_sum / $attempts, 1);

                    if ($average !== null) {
                        $avgBySection[(int) $section->id] = (float) $stat->percent_sum / $attempts;
                    }

                    if ($attempts > 0 && in_array((int) $section->id, $attemptable, true)) {
                        $attemptedHere++;
                    }

                    $outSections[] = [
                        'id'             => (int) $section->id,
                        'type'           => $section->type,
                        'title_tk'       => $section->title_tk,
                        'title_ru'       => $section->title_ru,
                        // Reserved by the contract; no English titles are
                        // authored yet.
                        'title_en'       => null,
                        'question_count' => $map['counts'][(int) $section->id] ?? 0,
                        'attempts'       => $attempts,
                        'average'        => $average,
                    ];
                }

                // The lessons sheet's four states. "empty" = nothing to
                // attempt (no published sections carrying questions), the
                // "no content yet" caption in the design.
                if ($attemptable === []) {
                    $state = 'empty';
                } elseif ($attemptedHere >= count($attemptable)) {
                    $state = 'completed';
                } elseif ($attemptedHere > 0) {
                    $state = 'in_progress';
                } else {
                    $state = 'not_started';
                }

                $attemptableTotal += count($attemptable);
                $attemptedTotal += $attemptedHere;

                // FR-13.8: the child unit's success rate splits equally
                // across its attemptable sections; unattempted ones weigh
                // in at zero. Null until the student has done anything —
                // the sheet shows no chip rather than a red 0%.
                $successRate = null;

                if ($attemptedHere > 0) {
                    $sum = 0.0;

                    foreach ($attemptable as $sectionId) {
                        $sum += $avgBySection[$sectionId] ?? 0.0;
                    }

                    $successRate = round($sum / count($attemptable), 1);
                }

                $outChildren[] = [
                    'id'           => (int) $child->id,
                    'label'        => $unit->number . '-' . $child->code,
                    'title'        => $child->title,
                    'success_rate' => $successRate,
                    'state'        => $state,
                    'sections'     => $outSections,
                ];
            }

            $parentUnits[] = [
                'id'          => (int) $unit->id,
                'number'      => (int) $unit->number,
                'title'       => $unit->title,
                'child_units' => $outChildren,
            ];
        }

        return [
            'level' => [
                'id'   => $levelId,
                'name' => Capsule::table('levels')->where('id', $levelId)->value('name'),
            ],
            // Coverage, not quality: how much of the level's content has
            // been completed at least once (FR-13.9).
            'level_progress' => $attemptableTotal === 0
                ? 0
                : (int) round($attemptedTotal / $attemptableTotal * 100),
            // FR-13.9: level correctness % × 100 (0–10 000).
            'leaderboard_score' => (int) round(
                StatsService::correctness($map['by_child'], $avgBySection) * 100
            ),
            'parent_units' => $parentUnits,
        ];
    }

    /**
     * The one gate every section read passes through: the section must
     * be published and belong to the student's own level. Deliberately
     * "not found" rather than "forbidden" — drafts and other levels'
     * content should not even be discoverable by probing ids.
     *
     * @return object the section row plus unit_id / unit_number / unit_code
     */
    public function requireSection(User $student, int $sectionId): object
    {
        $classroom = $student->classroom;

        if ($classroom === null) {
            throw ApiException::forbidden();
        }

        $row = Capsule::table('sections as sec')
            ->join('unit_sections as cu', 'cu.id', '=', 'sec.unit_section_id')
            ->join('units as u', 'u.id', '=', 'cu.unit_id')
            ->where('sec.id', $sectionId)
            ->where('sec.status', Section::STATUS_PUBLISHED)
            ->where('u.level_id', (int) $classroom->level_id)
            ->select([
                'sec.*',
                'cu.unit_id',
                'cu.code as unit_code',
                'u.number as unit_number',
            ])
            ->first();

        if ($row === null) {
            throw ApiException::notFound();
        }

        return $row;
    }

    // grammarIndexFor / grammarById served the grammar guide, removed
    // with the guide (FR-13.26, 2026-08-13).

    /** The grammar explanation of one typed section. */
    public function grammarFor(User $student, int $sectionId): array
    {
        $section = $this->requireSection($student, $sectionId);

        $grammar = Capsule::table('grammar_explanations')
            ->where('section_id', $section->id)
            ->first();

        if ($grammar === null) {
            throw ApiException::notFound();
        }

        return [
            'id'       => (int) $grammar->id,
            'title_tk' => $grammar->title_tk,
            'title_ru' => $grammar->title_ru,
            'body_tk'  => $grammar->body_tk,
            'body_ru'  => $grammar->body_ru,
            'examples' => json_decode((string) $grammar->examples, true) ?? [],
        ];
    }

    /**
     * The dictionary: every word from published sections of the
     * student's level, searchable, with the student's own bookmarks
     * resolved (FR-6.3).
     */
    public function vocabularyIndexFor(
        User $student,
        ?string $search = null,
        bool $onlyBookmarked = false,
        ?int $unitId = null,
    ): array {
        $classroom = $student->classroom;

        if ($classroom === null) {
            return [];
        }

        $bookmarked = array_map('intval', Capsule::table('student_bookmarks')
            ->where('student_id', $student->id)
            ->pluck('vocabulary_item_id')
            ->all());

        if ($onlyBookmarked && $bookmarked === []) {
            return [];
        }

        $rows = Capsule::table('vocabulary_items as v')
            ->join('sections as sec', 'sec.id', '=', 'v.section_id')
            ->join('unit_sections as cu', 'cu.id', '=', 'sec.unit_section_id')
            ->join('units as u', 'u.id', '=', 'cu.unit_id')
            ->where('u.level_id', (int) $classroom->level_id)
            ->where('sec.status', Section::STATUS_PUBLISHED)
            // The per-unit Vocabulary screen; the Vocabulary tab passes
            // no unit and reads across the whole level. The id is the
            // CHILD unit (1A/1B) — that is what the app opens words for.
            ->when($unitId !== null, fn ($q) => $q->where('cu.id', $unitId))
            ->when($onlyBookmarked, fn ($q) => $q->whereIn('v.id', $bookmarked))
            ->when($search !== null && $search !== '', function ($q) use ($search): void {
                $like = '%' . $search . '%';
                $q->where(fn ($w) => $w->where('v.term_en', 'like', $like)
                    ->orWhere('v.translation_tk', 'like', $like)
                    ->orWhere('v.translation_ru', 'like', $like));
            })
            ->orderBy('v.term_en')
            ->select([
                'v.id', 'v.term_en', 'v.part_of_speech', 'v.ipa',
                'v.translation_tk', 'v.translation_ru', 'v.example_en', 'v.audio_path',
                'v.category', 'v.word_type',
                'cu.code', 'u.number',
            ])
            ->get();

        return $rows->map(fn ($row): array => [
            'id'             => (int) $row->id,
            'term_en'        => $row->term_en,
            'part_of_speech' => $row->part_of_speech,
            'ipa'            => $row->ipa,
            'translation_tk' => $row->translation_tk,
            'translation_ru' => $row->translation_ru,
            'example_en'     => $row->example_en,
            'audio_path'     => $row->audio_path,
            // Wordlist v2's Category and Type — the word card's two chips
            // (FR-14.6/FR-15.6). Null when the row carried neither.
            'category'       => $row->category,
            'word_type'      => $row->word_type,
            // The word card's source-unit chip.
            'label'          => $row->number . '-' . $row->code,
            'bookmarked'     => in_array((int) $row->id, $bookmarked, true),
        ])->all();
    }

    /** The words of one typed (Vocabulary) section. */
    public function vocabularyFor(User $student, int $sectionId): array
    {
        $section = $this->requireSection($student, $sectionId);

        $bookmarked = array_map('intval', Capsule::table('student_bookmarks')
            ->where('student_id', $student->id)
            ->pluck('vocabulary_item_id')
            ->all());

        return Capsule::table('vocabulary_items')
            ->where('section_id', $section->id)
            ->orderBy('sort_order')
            ->get()
            ->map(fn ($item): array => [
                'id'             => (int) $item->id,
                'term_en'        => $item->term_en,
                'part_of_speech' => $item->part_of_speech,
                'ipa'            => $item->ipa,
                'translation_tk' => $item->translation_tk,
                'translation_ru' => $item->translation_ru,
                'example_en'     => $item->example_en,
                'audio_path'     => $item->audio_path,
                // Wordlist v2's Category and Type (FR-14.6/FR-15.6).
                'category'       => $item->category,
                'word_type'      => $item->word_type,
                // FR-6.3
                'bookmarked'     => in_array((int) $item->id, $bookmarked, true),
            ])->all();
    }

    /**
     * A vocabulary id alone says nothing about who may touch it, and
     * bookmarking takes one straight from the request. Resolve the
     * item's section and apply the same publish + level gate as
     * everything else.
     */
    public function requireVocabularyAccess(User $student, int $itemId): void
    {
        $sectionId = Capsule::table('vocabulary_items')->where('id', $itemId)->value('section_id');

        if ($sectionId === null) {
            throw ApiException::notFound();
        }

        $this->requireSection($student, (int) $sectionId);
    }
}
