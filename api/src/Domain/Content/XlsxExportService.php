<?php

declare(strict_types=1);

namespace Dana\Domain\Content;

use Closure;
use Dana\Domain\Models\ExerciseSet;
use Dana\Domain\Models\Section;
use Dana\Http\ApiException;
use Dana\Support\Xlsx\XlsxWriter;
use Illuminate\Database\Capsule\Manager as Capsule;

/**
 * The content workbook, database -> xlsx (FR-13.15, docs/06-CONTENT-V2.md §6).
 *
 * v2: the workbook's sheets are EXACTLY the client's five shapes, so an
 * export re-imports with zero changes:
 *
 *  - `Listening` / `Grammar` / `Vocabulary` — question sheets (§1.1
 *    header). One row per active question of that typed section, in file
 *    order (`questions.sort_order`), its stem and MC options re-serialised
 *    as `[audio: …, image: …, text: …]` triples. The CORRECT option is
 *    written back as Option A — the reverse of the import shuffle (§2) —
 *    so the reconcile on re-import restores the stored order untouched.
 *  - `Wordlist` (§1.2) — one row per vocabulary item.
 *  - `UnitQuiz` (§1.3) — one block per child unit in scope that set any
 *    quiz target.
 *
 * Scope is the whole database, one level, or one parent unit. Quiz
 * sections own no questions (FR-13.4) and never appear in a question
 * sheet; soft-deleted questions (is_active = 0) are attempt-history
 * bookkeeping, not content, and are skipped; match_pairs has no v2 file
 * shape and is skipped too. Student data is never touched.
 */
final class XlsxExportService
{
    public const SCOPE_ALL   = 'all';
    public const SCOPE_LEVEL = 'level';
    public const SCOPE_UNIT  = 'unit';

    /** §1.1 — the client's header, tolerated typo `Eligble` and all. */
    public const QUESTION_HEADER = [
        'Question ID', 'Level', 'Unit', 'Lesson', 'Topic', 'Subtopic',
        'Question Type', 'Rule', 'Question', 'Option A', 'Option B',
        'Option C', 'Option D', 'Answer Description', 'Eligble',
    ];

    /** §1.2 */
    public const WORDLIST_HEADER = [
        'Word ID', 'Level', 'Unit', 'Lesson', 'Category', 'Type',
        'English', 'Turkmen', 'Russian',
    ];

    /** §1.3 — the "Content Inventory" columns; blocks follow underneath. */
    public const UNITQUIZ_HEADER = ['Skill', 'Item Count', 'Unit Quiz Target'];

    /** set type -> the file's Question Type value; null = no v2 shape. */
    private const TYPE_LABELS = [
        ExerciseSet::TYPE_MULTIPLE_CHOICE   => 'Test',
        ExerciseSet::TYPE_REORDER           => 'ReOrder',
        ExerciseSet::TYPE_FILL_LETTER_SPACE => 'LetterSpace',
        ExerciseSet::TYPE_FILL_BLANK        => 'FillBlank',
    ];

    /** The three question sheets, in workbook order. */
    private const SKILL_SHEETS = [
        Section::TYPE_LISTENING  => 'Listening',
        Section::TYPE_GRAMMAR    => 'Grammar',
        Section::TYPE_VOCABULARY => 'Vocabulary',
    ];

    /**
     * Builds the workbook for one scope. The caller streams it out with
     * XlsxWriter::toString().
     */
    public function workbook(string $scope, ?int $id): XlsxWriter
    {
        $filter = $this->filter($scope, $id);
        $questions = $this->questionRows($filter);

        $writer = new XlsxWriter();

        foreach (self::SKILL_SHEETS as $type => $sheet) {
            $writer->addSheet($sheet, self::QUESTION_HEADER, $questions[$type] ?? []);
        }

        $writer->addSheet('Wordlist', self::WORDLIST_HEADER, $this->wordlistRows($filter));
        $writer->addSheet('UnitQuiz', self::UNITQUIZ_HEADER, $this->unitQuizRows($filter));

        return $writer;
    }

    // ------------------------------------------------------------ internals

    /**
     * The scope as a WHERE on the shared join base (`l` = levels,
     * `u` = units). 404 for a level/unit that does not exist, so the
     * panel shows "not found" instead of downloading an empty workbook.
     *
     * @return Closure(\Illuminate\Database\Query\Builder): \Illuminate\Database\Query\Builder
     */
    private function filter(string $scope, ?int $id): Closure
    {
        switch ($scope) {
            case self::SCOPE_ALL:
                return static fn ($query) => $query;

            case self::SCOPE_LEVEL:
                if ($id === null || $id < 1) {
                    throw ApiException::validation('Dereje ID-si gerek.', 'Нужен ID уровня.');
                }

                if (!Capsule::table('levels')->where('id', $id)->exists()) {
                    throw ApiException::notFound();
                }

                return static fn ($query) => $query->where('l.id', $id);

            case self::SCOPE_UNIT:
                if ($id === null || $id < 1) {
                    throw ApiException::validation('Bölüm ID-si gerek.', 'Нужен ID юнита.');
                }

                if (!Capsule::table('units')->where('id', $id)->exists()) {
                    throw ApiException::notFound();
                }

                return static fn ($query) => $query->where('u.id', $id);
        }

        throw ApiException::validation(
            'Eksport gerimi nädogry: all, level ýa-da unit.',
            'Недопустимая область экспорта: all, level или unit.'
        );
    }

    /**
     * Child units joined up to their level, in teaching order — the
     * spine every other sheet hangs its context columns on.
     */
    private function childUnits(Closure $filter): \Illuminate\Database\Query\Builder
    {
        return $filter(
            Capsule::table('unit_sections as cu')
                ->join('units as u', 'u.id', '=', 'cu.unit_id')
                ->join('levels as l', 'l.id', '=', 'u.level_id')
        )
            ->orderBy('l.sort_order')->orderBy('l.id')
            ->orderBy('u.sort_order')->orderBy('u.number')
            ->orderBy('cu.sort_order')->orderBy('cu.id');
    }

    /**
     * Question rows for the three skill sheets, grouped by section type.
     * Ordered by child unit then `questions.sort_order` — the per-section
     * file order, NOT grouped by exercise set, so re-import recomputes the
     * same sort_order values.
     *
     * @return array<string, list<array<int, string|int>>>
     */
    private function questionRows(Closure $filter): array
    {
        $rows = $this->childUnits($filter)
            ->join('sections as s', 's.unit_section_id', '=', 'cu.id')
            ->join('exercise_sets as es', 'es.section_id', '=', 's.id')
            ->join('questions as q', 'q.exercise_set_id', '=', 'es.id')
            ->whereIn('s.type', array_keys(self::SKILL_SHEETS))
            // Soft-deleted questions are attempt-history bookkeeping.
            ->where('q.is_active', 1)
            ->orderBy('q.sort_order')->orderBy('q.id')
            ->select([
                's.type as section_type', 'es.type as set_type',
                'q.external_code', 'l.name as level', 'u.number', 'cu.code',
                'q.topic', 'q.subtopic', 'q.rule_en', 'q.answer_description_en',
                'q.quiz_eligible', 'q.payload',
            ])
            ->get();

        $out = [];

        foreach (array_keys(self::SKILL_SHEETS) as $type) {
            $out[$type] = [];
        }

        foreach ($rows as $r) {
            $label = self::TYPE_LABELS[(string) $r->set_type] ?? null;

            if ($label === null) {
                continue; // match_pairs (or anything unknown) has no v2 shape
            }

            [$question, $a, $b, $c, $d] = $this->serialiseQuestion((string) $r->set_type, (string) $r->payload);

            $out[(string) $r->section_type][] = [
                (string) $r->external_code,
                (string) $r->level,
                (int) $r->number,
                $r->number . $r->code,
                (string) ($r->topic ?? ''),
                (string) ($r->subtopic ?? ''),
                $label,
                (string) ($r->rule_en ?? ''),
                $question, $a, $b, $c, $d,
                (string) ($r->answer_description_en ?? ''),
                (int) $r->quiz_eligible === 1 ? 'Yes' : '',
            ];
        }

        return $out;
    }

    /**
     * A question's Question cell and its four Option cells (§1.1). For a
     * multiple_choice question the CORRECT option is written as Option A
     * (the reverse of the import shuffle, §2), the rest in stored order;
     * the other types own no options.
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    private function serialiseQuestion(string $setType, string $payloadJson): array
    {
        $p = json_decode($payloadJson, true);
        $p = is_array($p) ? $p : [];

        switch ($setType) {
            case ExerciseSet::TYPE_MULTIPLE_CHOICE:
                $options = array_values($p['options'] ?? []);
                $answer = (int) ($p['answer'] ?? 0);

                // Correct first (Option A), then the rest in stored order.
                $ordered = [];

                if (array_key_exists($answer, $options)) {
                    $ordered[] = $options[$answer];
                }

                foreach ($options as $i => $option) {
                    if ($i !== $answer) {
                        $ordered[] = $option;
                    }
                }

                $cells = ['', '', '', ''];

                foreach (array_slice($ordered, 0, 4) as $i => $option) {
                    $cells[$i] = self::triple($option);
                }

                return [self::triple($p['stem'] ?? ''), $cells[0], $cells[1], $cells[2], $cells[3]];

            case ExerciseSet::TYPE_REORDER:
                $tokens = array_map('strval', $p['tokens'] ?? []);
                $cell = $tokens === [] ? '' : '{{' . implode('}} {{', $tokens) . '}}';

                return [$cell, '', '', '', ''];

            case ExerciseSet::TYPE_FILL_LETTER_SPACE:
                $mask = array_key_exists('mask', $p)
                    ? (string) $p['mask']
                    : self::legacyMask((string) ($p['text'] ?? ''), (int) ($p['reveal'] ?? 0));

                return ['{{' . $mask . '}}', '', '', '', ''];

            case ExerciseSet::TYPE_FILL_BLANK:
                $bank = array_map('strval', $p['word_bank'] ?? []);
                $cell = (string) ($p['before'] ?? '')
                    . '{{' . implode('~', $bank) . '}}'
                    . (string) ($p['after'] ?? '');

                return [$cell, '', '', '', ''];
        }

        return ['', '', '', '', ''];
    }

    /** One `[audio: …, image: …, text: …]` cell for a v2 part (or a legacy scalar). */
    private static function triple(mixed $part): string
    {
        if (is_array($part)) {
            if (isset($part['text'])) {
                return self::tripleCell(null, null, (string) $part['text']);
            }

            if (isset($part['audio_note'])) {
                return self::tripleCell((string) $part['audio_note'], null, null);
            }

            if (isset($part['image_note'])) {
                return self::tripleCell(null, (string) $part['image_note'], null);
            }
        }

        return self::tripleCell(null, null, (string) $part);
    }

    private static function tripleCell(?string $audio, ?string $image, ?string $text): string
    {
        $member = static fn (?string $v): string => $v === null ? '0' : '"' . $v . '"';

        return '[audio: ' . $member($audio)
            . ', image: ' . $member($image)
            . ', text: ' . $member($text) . ']';
    }

    /**
     * A legacy `{word}` + reveal payload rendered as a v2 mask: the
     * hidden tail of each target word wrapped in `<>`. Only reached for
     * pre-v2 content; v2 questions already store their mask.
     */
    private static function legacyMask(string $text, int $reveal): string
    {
        $reveal = max(0, $reveal);

        return (string) preg_replace_callback(
            '/\{([^{}]+)\}/u',
            static function (array $m) use ($reveal): string {
                $word = $m[1];
                $shown = min($reveal, mb_strlen($word));
                $hidden = mb_substr($word, $shown);

                return mb_substr($word, 0, $shown) . ($hidden === '' ? '' : '<' . $hidden . '>');
            },
            $text
        );
    }

    /** @return list<array<int, string|int>> */
    private function wordlistRows(Closure $filter): array
    {
        return $this->childUnits($filter)
            ->join('sections as s', 's.unit_section_id', '=', 'cu.id')
            ->join('vocabulary_items as v', 'v.section_id', '=', 's.id')
            ->where('s.type', Section::TYPE_VOCABULARY)
            ->orderBy('v.sort_order')->orderBy('v.id')
            ->select([
                'v.external_code', 'l.name as level', 'u.number', 'cu.code',
                'v.category', 'v.word_type', 'v.term_en', 'v.translation_tk', 'v.translation_ru',
            ])
            ->get()
            ->map(static fn ($r): array => [
                (string) $r->external_code,
                (string) $r->level,
                (int) $r->number,
                $r->number . $r->code,
                (string) ($r->category ?? ''),
                $r->word_type === 'phrase' ? 'Phrase' : 'Word',
                (string) $r->term_en,
                (string) $r->translation_tk,
                (string) $r->translation_ru,
            ])
            ->all();
    }

    /**
     * One §1.3 block per child unit that set a quiz target: a
     * `UNIT <n><code>` title row, then a Skill / Item Count / Unit Quiz
     * Target row per skill whose target is set. Children with no targets
     * are skipped (an empty target column would fail re-import).
     *
     * @return list<array<int, string|int>>
     */
    private function unitQuizRows(Closure $filter): array
    {
        $children = $this->childUnits($filter)
            ->select([
                'cu.id', 'u.number', 'cu.code',
                'cu.quiz_target_vocabulary', 'cu.quiz_target_grammar', 'cu.quiz_target_listening',
            ])
            ->get();

        $rows = [];

        foreach ($children as $c) {
            $targets = [
                ['Vocabulary', $c->quiz_target_vocabulary, Section::TYPE_VOCABULARY],
                ['Grammar',    $c->quiz_target_grammar,    Section::TYPE_GRAMMAR],
                ['Listening',  $c->quiz_target_listening,  Section::TYPE_LISTENING],
            ];

            if ($c->quiz_target_vocabulary === null
                && $c->quiz_target_grammar === null
                && $c->quiz_target_listening === null) {
                continue;
            }

            $rows[] = ['UNIT ' . $c->number . $c->code, '', ''];

            foreach ($targets as [$label, $target, $type]) {
                if ($target === null) {
                    continue;
                }

                $rows[] = [$label, $this->skillItemCount((int) $c->id, $type), (int) $target];
            }
        }

        return $rows;
    }

    /** The informational Item Count: active questions of that skill in the child. */
    private function skillItemCount(int $childId, string $type): int
    {
        return (int) Capsule::table('questions as q')
            ->join('exercise_sets as es', 'es.id', '=', 'q.exercise_set_id')
            ->join('sections as s', 's.id', '=', 'es.section_id')
            ->where('s.unit_section_id', $childId)
            ->where('s.type', $type)
            ->where('q.is_active', 1)
            ->count();
    }
}
