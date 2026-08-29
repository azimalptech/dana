<?php

declare(strict_types=1);

namespace Dana\Domain\Content;

use Dana\Domain\Models\ExerciseSet;
use Dana\Domain\Models\Section;
use Dana\Http\ApiException;
use Dana\Support\Xlsx\XlsxReader;
use Illuminate\Database\Capsule\Manager as Capsule;
use RuntimeException;
use Throwable;

/**
 * The client's content files, xlsx -> database (FR-14.1).
 *
 * v2 (docs/06-CONTENT-V2.md §1): the five files the client authors ARE
 * the structure — question sheets (Listening / Grammar / Vocabulary),
 * the Wordlist and the UnitQuiz inventory. The panel may upload several
 * files in one request; sheets are recognised by their header signature,
 * the skill of a question sheet by its sheet (or file) name. The old
 * four-sheet authoring format is retired.
 *
 *  - Rows are upserted by their file identity — `Question ID` /
 *    `Word ID` -> external_code. Same code = update, new code = insert;
 *    numeric database ids never appear in the files.
 *  - The row's Level must already exist ("Beginner / A1" matches a level
 *    named like either half — §1.1); Unit + Lesson create the parent and
 *    child unit on the way down, the skill creates its typed section
 *    (draft) when absent. Levels are made in the panel, not conjured
 *    from a cell typo.
 *  - Question stems and Test options are [audio: …, image: …, text: …]
 *    triples; audio/image values are NOTES for the superadmin, the real
 *    file arrives later in the panel. Parts whose note is unchanged keep
 *    their uploaded media_path across a re-import; a changed note clears
 *    it, and every part still lacking a file is listed in the response's
 *    media_pending so the panel can show the upload queue.
 *  - Questions keep FILE ORDER via a per-section sort_order counter,
 *    whatever set (type) each row lands in; one exercise set per
 *    (section, type), created on demand with a bilingual title.
 *  - The UnitQuiz sheet writes the child unit's quiz targets, ensures a
 *    quiz section exists, and clears the fixed draw when the targets (or
 *    the unit's imported content) changed, so it re-draws lazily
 *    (FR-14.3).
 *  - FR-13.20: nothing reaches a student from a spreadsheet. Every
 *    section the import creates or writes into is forced to draft;
 *    publishing stays a deliberate act in the panel.
 *  - All or nothing, across ALL uploaded files: every row is checked,
 *    every error is reported with its file, sheet and Excel row number,
 *    and a single error rolls the whole transaction back.
 *
 * Student data is never read or written here, and imports never delete
 * content — the one deletion is the quiz draw, which is a cache of a
 * random choice, not authored content.
 */
final class XlsxImportService
{
    private const ENTITIES = [
        'units', 'child_units', 'sections',
        'exercise_sets', 'questions', 'vocabulary_items', 'quiz_targets',
    ];

    /** Question-sheet skill -> the typed section it fills (§1.1). */
    private const SKILLS = [
        'listening'  => Section::TYPE_LISTENING,
        'grammar'    => Section::TYPE_GRAMMAR,
        'vocabulary' => Section::TYPE_VOCABULARY,
    ];

    /**
     * Header cells as they appear in the real files -> internal keys.
     * The client's typos (`Answer Desription`, `Eligble`) are first-class
     * spellings here, not errors (§1.1).
     */
    private const QUESTION_COLUMNS = [
        'question id'        => 'qid',
        'level'              => 'level',
        'unit'               => 'unit',
        'lesson'             => 'lesson',
        'topic'              => 'topic',
        'subtopic'           => 'subtopic',
        'question type'      => 'qtype',
        'rule'               => 'rule',
        'question'           => 'question',
        'option a'           => 'opt0',
        'option b'           => 'opt1',
        'option c'           => 'opt2',
        'option d'           => 'opt3',
        'answer description' => 'answerdesc',
        'answer desription'  => 'answerdesc',
        // The client's 2026-08-27 files renamed the column (§1.1) — same
        // content, the verdict sheet's ANSWER DESCRIPTION line.
        'feedback'           => 'answerdesc',
        'eligble'            => 'eligible',
        'eligible'           => 'eligible',
    ];

    private const QUESTION_REQUIRED = ['qid', 'level', 'unit', 'lesson', 'qtype', 'question'];

    private const WORDLIST_COLUMNS = [
        'word id'  => 'wid',
        'level'    => 'level',
        'unit'     => 'unit',
        'lesson'   => 'lesson',
        'category' => 'category',
        'type'     => 'wtype',
        'english'  => 'en',
        'turkmen'  => 'tk',
        'russian'  => 'ru',
    ];

    private const WORDLIST_REQUIRED = ['wid', 'level', 'unit', 'lesson', 'en', 'tk', 'ru'];

    /**
     * Titles for the sets the import creates on demand. The set is an
     * implementation detail in v2 — the file has no set concept — so the
     * title just names the exercise type for the app's list.
     */
    private const SET_TITLES = [
        ExerciseSet::TYPE_MULTIPLE_CHOICE   => ['Test', 'Тест'],
        ExerciseSet::TYPE_REORDER           => ['Sözlemi düzüň', 'Составьте предложение'],
        ExerciseSet::TYPE_FILL_LETTER_SPACE => ['Harplary dolduryň', 'Впишите буквы'],
        ExerciseSet::TYPE_FILL_BLANK        => ['Boşlugy dolduryň', 'Заполните пропуск'],
    ];

    /** @var array<string, int> */
    private array $created = [];

    /** @var array<string, int> */
    private array $updated = [];

    /** @var list<array{file: string, sheet: string, row: int, message_ru: string}> */
    private array $errors = [];

    /** @var list<array{external_code: string, part: string, note: string}> */
    private array $mediaPending = [];

    /** @var array<int, true> section ids the import wrote into — forced draft */
    private array $touched = [];

    /** @var array<string, int> lower(external_code) => Excel row already claimed this import */
    private array $seenQuestionCodes = [];

    /** @var array<string, int> */
    private array $seenWordCodes = [];

    /** @var array<int, int> section id => last sort_order handed out (file order, §1.1) */
    private array $sortNext = [];

    /** @var array<string, int|null> lower(level cell) => level id */
    private array $levelCache = [];

    private string $now = '';

    /**
     * Imports one or more of the client's v2 files as a single
     * all-or-nothing transaction.
     *
     * @param list<array{name: string, path: string}> $files uploaded
     *        workbooks — `name` is the client filename (skill fallback +
     *        error reporting), `path` a readable local file
     * @return array{
     *     created: array<string, int>,
     *     updated: array<string, int>,
     *     errors: list<array{file: string, sheet: string, row: int, message_ru: string}>,
     *     media_pending: list<array{external_code: string, part: string, note: string}>
     * }
     */
    public function import(array $files): array
    {
        $this->created = array_fill_keys(self::ENTITIES, 0);
        $this->updated = array_fill_keys(self::ENTITIES, 0);
        $this->errors = [];
        $this->mediaPending = [];
        $this->touched = [];
        $this->seenQuestionCodes = [];
        $this->seenWordCodes = [];
        $this->sortNext = [];
        $this->levelCache = [];
        $this->now = date('Y-m-d H:i:s');

        if ($files === []) {
            throw ApiException::validation(
                '.xlsx faýly saýlanmady.',
                'Файл .xlsx не выбран.'
            );
        }

        // Read and classify everything up front so a broken second file
        // is reported before the first one has written anything.
        $questionSheets = []; // [file, sheet, skill, rows]
        $wordlistSheets = []; // [file, sheet, rows]
        $quizSheets = [];     // [file, sheet, rows]

        foreach ($files as $file) {
            $name = $file['name'];

            try {
                $sheets = XlsxReader::open($file['path'])->sheets();
            } catch (RuntimeException $e) {
                throw ApiException::validation(
                    "«{$name}» .xlsx görnüşinde okalmady.",
                    "«{$name}» не читается как .xlsx: " . $e->getMessage()
                );
            }

            foreach ($sheets as $sheetName => $rows) {
                $sheetName = (string) $sheetName;

                if ($rows === []) {
                    continue; // an empty sheet has nothing to say
                }

                switch (self::kindOf($rows)) {
                    case 'questions':
                        if (($skill = self::skillOf($sheetName, $name)) === null) {
                            $this->err($name, $sheetName, 1, 'Не удалось определить навык листа с вопросами — назовите лист или файл Listening, Grammar или Vocabulary.');
                            break;
                        }

                        $questionSheets[] = [$name, $sheetName, $skill, $rows];
                        break;

                    case 'wordlist':
                        $wordlistSheets[] = [$name, $sheetName, $rows];
                        break;

                    case 'quiz':
                        $quizSheets[] = [$name, $sheetName, $rows];
                        break;

                    default:
                        $this->err($name, $sheetName, 1, 'Лист не соответствует ни одной из форм v2 (Listening/Grammar/Vocabulary, Wordlist, UnitQuiz).');
                }
            }
        }

        if ($questionSheets === [] && $wordlistSheets === [] && $quizSheets === [] && $this->errors === []) {
            throw ApiException::validation(
                'Faýllarda Listening/Grammar/Vocabulary, Wordlist ýa-da UnitQuiz listleri ýok.',
                'В файлах нет ни одного листа форматов Listening/Grammar/Vocabulary, Wordlist или UnitQuiz.'
            );
        }

        $connection = Capsule::connection();
        $connection->beginTransaction();

        try {
            // Content first, UnitQuiz last — its unit lookup must see the
            // units the question and word files just created.
            foreach ($questionSheets as [$file, $sheet, $skill, $rows]) {
                $this->questionSheet($file, $sheet, $skill, $rows);
            }

            foreach ($wordlistSheets as [$file, $sheet, $rows]) {
                $this->wordlistSheet($file, $sheet, $rows);
            }

            foreach ($quizSheets as [$file, $sheet, $rows]) {
                $this->quizSheet($file, $sheet, $rows);
            }

            // Skip-and-report (client decision, 2026-08-14): a malformed
            // row is reported and skipped, but every VALID row still
            // lands. One typo in a 134-row file must not block the whole
            // upload — the superadmin fixes the listed rows and re-uploads
            // (upsert by external_code updates in place). A malformed row
            // is rejected during validation, before any write, so the
            // committed data is exactly the valid rows. A fatal Throwable
            // still rolls the entire batch back below.

            // FR-13.20: whatever the import wrote into goes behind the
            // publish gate. Untouched sections keep their status.
            if ($this->touched !== []) {
                Capsule::table('sections')
                    ->whereIn('id', array_keys($this->touched))
                    ->where('status', '!=', Section::STATUS_DRAFT)
                    ->update(['status' => Section::STATUS_DRAFT, 'updated_at' => $this->now]);
            }

            $connection->commit();
        } catch (Throwable $e) {
            $connection->rollBack();

            throw $e;
        }

        return [
            'created'       => $this->created,
            'updated'       => $this->updated,
            'errors'        => $this->errors,
            'media_pending' => $this->mediaPending,
        ];
    }

    // ------------------------------------------------------ sheet detection

    /**
     * What a sheet IS, from its header signature — never from its name,
     * which the client edits freely ("FINAL Word List", "Content
     * Inventory").
     *
     * @param list<list<string>> $rows
     */
    private static function kindOf(array $rows): ?string
    {
        foreach (array_slice($rows, 0, 10) as $row) {
            $cells = array_map(self::norm(...), $row);

            if (in_array('question id', $cells, true) && in_array('question type', $cells, true)) {
                return 'questions';
            }

            if (in_array('word id', $cells, true) && in_array('english', $cells, true)) {
                return 'wordlist';
            }

            if (($cells[0] ?? '') === 'skill' && in_array('unit quiz target', $cells, true)) {
                return 'quiz';
            }
        }

        return null;
    }

    /** The skill (= section type) a question sheet fills, from its sheet or file name (§1.1). */
    private static function skillOf(string $sheetName, string $fileName): ?string
    {
        foreach ([$sheetName, $fileName] as $candidate) {
            $candidate = mb_strtolower($candidate);

            foreach (array_keys(self::SKILLS) as $skill) {
                if (str_contains($candidate, $skill)) {
                    return $skill;
                }
            }
        }

        return null;
    }

    /**
     * Resolves a header row against an alias map. Returns [cols, headerRow]
     * with cols = internal key => column index, or null after recording
     * the missing-column error.
     *
     * @param list<list<string>> $rows
     * @param array<string, string> $aliases
     * @param list<string> $required
     * @return array{0: array<string, int>, 1: int}|null
     */
    private function header(string $file, string $sheet, array $rows, array $aliases, array $required, string $anchor): ?array
    {
        foreach ($rows as $i => $row) {
            if (!in_array($anchor, array_map(self::norm(...), $row), true)) {
                continue;
            }

            $cols = [];

            foreach ($row as $index => $cell) {
                $key = $aliases[self::norm($cell)] ?? null;

                if ($key !== null && !isset($cols[$key])) {
                    $cols[$key] = $index;
                }
            }

            $missing = array_diff($required, array_keys($cols));

            if ($missing !== []) {
                $this->err($file, $sheet, $i + 1, 'Нет обязательных колонок: ' . implode(', ', $missing) . '.');

                return null;
            }

            return [$cols, $i];
        }

        $this->err($file, $sheet, 1, 'Не найдена строка заголовков.');

        return null;
    }

    /** @param array<string, int> $cols */
    private static function cells(array $cols, array $row): array
    {
        $out = [];

        foreach ($cols as $key => $index) {
            $out[$key] = trim($row[$index] ?? '');
        }

        return $out;
    }

    // ---------------------------------------------------- question sheets

    /** @param list<list<string>> $rows */
    private function questionSheet(string $file, string $sheet, string $skill, array $rows): void
    {
        $resolved = $this->header($file, $sheet, $rows, self::QUESTION_COLUMNS, self::QUESTION_REQUIRED, 'question id');

        if ($resolved === null) {
            return;
        }

        [$cols, $headerRow] = $resolved;

        foreach ($rows as $i => $row) {
            if ($i <= $headerRow || array_filter($row, static fn (string $c): bool => $c !== '') === []) {
                continue;
            }

            $this->questionRow($file, $sheet, $skill, self::cells($cols, $row), $i + 1);
        }
    }

    /** @param array<string, string> $c */
    private function questionRow(string $file, string $sheet, string $skill, array $c, int $r): void
    {
        if (($code = $this->externalCode($c['qid'], 'Question ID', $this->seenQuestionCodes, $file, $sheet, $r)) === null) {
            return;
        }

        if (($setType = self::setType($c['qtype'])) === null) {
            // FR-4.20: an unsupported type is reported, never padded into
            // something gradable-looking.
            $this->err($file, $sheet, $r, "Неподдерживаемый Question Type «{$c['qtype']}».");

            return;
        }

        if (($childId = $this->contextChild($c, $file, $sheet, $r)) === null) {
            return;
        }

        if (($sectionId = $this->sectionOfType($childId, self::SKILLS[$skill], $file, $sheet, $r)) === null) {
            return;
        }

        if (($setId = $this->setOfType($sectionId, $setType, $file, $sheet, $r)) === null) {
            return;
        }

        $payload = match ($setType) {
            ExerciseSet::TYPE_MULTIPLE_CHOICE   => $this->mcPayload($c, $file, $sheet, $r),
            ExerciseSet::TYPE_REORDER           => $this->reorderPayload($c['question'], $file, $sheet, $r),
            ExerciseSet::TYPE_FILL_LETTER_SPACE => $this->letterPayload($c['question'], $file, $sheet, $r),
            ExerciseSet::TYPE_FILL_BLANK        => $this->blankPayload($c['question'], $file, $sheet, $r),
            default                             => null,
        };

        if ($payload === null
            || ($payload = $this->validPayload($setType, $payload, $file, $sheet, $r)) === null) {
            return;
        }

        // File order survives whatever set the row lands in: one running
        // counter per section (§1.1).
        $sort = $this->sortNext[$sectionId] = ($this->sortNext[$sectionId] ?? 0) + 1;

        $fields = [
            'exercise_set_id'       => $setId,
            // The column drives the app's frame choice (§5): the STEM's
            // media kind, text when the stem is plain text.
            'question_type'         => match (true) {
                isset($payload['stem']['audio_note']) => 'audio',
                isset($payload['stem']['image_note']) => 'picture',
                default                               => 'text',
            },
            // Column present -> authoritative yes/no (FR-14.1). Column
            // ABSENT from the sheet -> eligible, matching the panel's
            // opt-out default (FR-15.11): the client's 2026-08-27 files
            // dropped the column entirely, and importing a whole unit
            // with zero eligible questions starves the quiz draw.
            'quiz_eligible'         => array_key_exists('eligible', $c)
                ? (self::norm($c['eligible']) === 'yes' ? 1 : 0)
                : 1,
            'topic'                 => mb_substr($c['topic'] ?? '', 0, 120) ?: null,
            'subtopic'              => mb_substr($c['subtopic'] ?? '', 0, 120) ?: null,
            'rule_en'               => mb_substr($c['rule'] ?? '', 0, 255) ?: null,
            'answer_description_en' => mb_substr($c['answerdesc'] ?? '', 0, 500) ?: null,
            'sort_order'            => $sort,
            'is_active'             => 1,
        ];

        $existing = Capsule::table('questions')->where('external_code', $code)->first();

        if ($existing === null) {
            // §2 leak fix: the file has Option A (the correct one) first,
            // so a fresh multiple_choice question is SHUFFLED on insert and
            // `answer` moved to the correct option's new index. The stored
            // answer then sits at a random position, so serving each option
            // its stored index i (and naming its media q{id}-opt{i}) leaks
            // nothing. Only on first insert — a re-import keeps the order.
            if ($setType === ExerciseSet::TYPE_MULTIPLE_CHOICE) {
                $payload = self::shuffleMcOptions($payload);
            }

            Capsule::table('questions')->insert($fields + [
                'external_code'   => $code,
                'payload'         => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'is_human_edited' => 1, // authored in Excel by a person
                'created_at'      => $this->now,
                'updated_at'      => $this->now,
            ]);
            $this->created['questions']++;
            $this->touched[$sectionId] = true;
            $this->collectMediaPending($code, $payload);

            return;
        }

        // Same ID = update (§1.1). Media uploaded against an unchanged
        // note survives the re-import; a changed note clears the part's
        // file, and media_pending reports it for re-upload.
        $oldPayload = json_decode((string) $existing->payload, true);
        $oldPayload = is_array($oldPayload) ? $oldPayload : [];

        // The stored MC option order is the shuffle chosen at first insert
        // (§2). A re-import must NOT reshuffle it, or the answer index and
        // the q{id}-opt{i} media files would churn — so the file-order
        // options are re-mapped back onto the stored order. An unchanged
        // re-import then stays a pure no-op; a genuinely changed option set
        // has nothing to preserve and is dealt a fresh shuffle.
        if ($setType === ExerciseSet::TYPE_MULTIPLE_CHOICE) {
            $payload = self::reconcileMcOrder($payload, $oldPayload);
        }

        $payload = self::carryMedia($payload, $oldPayload);

        $changed = [];

        foreach (['question_type', 'topic', 'subtopic', 'rule_en', 'answer_description_en'] as $column) {
            if ($fields[$column] !== $existing->{$column}) {
                $changed[$column] = $fields[$column];
            }
        }

        foreach (['exercise_set_id', 'quiz_eligible', 'sort_order', 'is_active'] as $column) {
            if ($fields[$column] !== (int) $existing->{$column}) {
                $changed[$column] = $fields[$column];
            }
        }

        // Loose comparison: same keys and values = same payload, whatever
        // order the JSON listed them in — a pure re-import stays a no-op.
        if ($payload != $oldPayload) {
            $changed['payload'] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }

        if ($changed !== []) {
            $changed['is_human_edited'] = 1;
            $changed['updated_at'] = $this->now;
            Capsule::table('questions')->where('id', $existing->id)->update($changed);
            $this->updated['questions']++;
            $this->touched[$sectionId] = true;

            // The row may have moved between sections; the one it left
            // changed too, so it goes behind the publish gate as well.
            if ((int) $existing->exercise_set_id !== $setId) {
                $oldSection = Capsule::table('exercise_sets')
                    ->where('id', (int) $existing->exercise_set_id)->value('section_id');

                if ($oldSection !== null) {
                    $this->touched[(int) $oldSection] = true;
                }
            }
        }

        $this->collectMediaPending($code, $payload);
    }

    /**
     * `Test` (and its Practical Dialogue listening variants): the
     * Question cell is the stem triple, Option A..D the option triples —
     * Option A is ALWAYS the correct one in the file, so `answer` is the
     * original index 0 and the serve-time shuffle hides it (FR-12.6).
     *
     * @param array<string, string> $c
     * @return array<string, mixed>|null
     */
    private function mcPayload(array $c, string $file, string $sheet, int $r): ?array
    {
        $stem = $this->triplePart($c['question']);

        if (is_string($stem)) {
            $this->err($file, $sheet, $r, 'Question: ' . $stem);

            return null;
        }

        $options = [];
        $gap = false;

        foreach (['opt0', 'opt1', 'opt2', 'opt3'] as $i => $key) {
            $cell = $c[$key] ?? '';

            if ($cell === '') {
                $gap = true;
                continue;
            }

            if ($gap) {
                $this->err($file, $sheet, $r, 'Option ' . chr(65 + $i) . ' идёт после пустой колонки — варианты должны заполняться подряд от Option A.');

                return null;
            }

            $part = $this->triplePart($cell);

            if (is_string($part)) {
                $this->err($file, $sheet, $r, 'Option ' . chr(65 + $i) . ': ' . $part);

                return null;
            }

            $options[] = $part;
        }

        if (count($options) < 2) {
            $this->err($file, $sheet, $r, 'Нужно от 2 до 4 вариантов (Option A — правильный).');

            return null;
        }

        return ['stem' => $stem, 'options' => $options, 'answer' => 0];
    }

    /**
     * `[audio: X, image: Y, text: Z]` — exactly one member non-"0"
     * (§1.1). Quoted values may span lines (a two-line dialogue note).
     * Returns the payload part, or a Russian error string.
     *
     * @return array<string, mixed>|string
     */
    private function triplePart(string $cell)
    {
        $re = '/^\[\s*audio\s*:\s*(?:"(?<audio>.*?)"|0)?\s*,\s*image\s*:\s*(?:"(?<image>.*?)"|0)?\s*,\s*text\s*:\s*(?:"(?<text>.*)"|0)?\s*\]$/su';

        if (preg_match($re, trim($cell), $m) !== 1) {
            return 'Ячейка не в формате [audio: …, image: …, text: …] (значения в кавычках или 0).';
        }

        $used = [];

        foreach (['audio', 'image', 'text'] as $member) {
            if (($m[$member] ?? '') !== '' && trim($m[$member]) !== '') {
                $used[$member] = $m[$member];
            }
        }

        if (count($used) !== 1) {
            return 'Ровно одна часть из audio/image/text должна быть заполнена, остальные — 0.';
        }

        $value = (string) reset($used);

        return match (array_key_first($used)) {
            'text'  => ['text' => $value],
            'audio' => ['audio_note' => $value, 'media_path' => null],
            default => ['image_note' => $value, 'media_path' => null],
        };
    }

    /**
     * `ReOrder`: the Question cell holds the CORRECT sentence as
     * `{{tok}} {{tok}} …` — token order IS the answer (§2).
     *
     * @return array<string, mixed>|null
     */
    private function reorderPayload(string $cell, string $file, string $sheet, int $r): ?array
    {
        preg_match_all('/\{\{(.*?)\}\}/su', $cell, $m);
        $tokens = array_map('trim', $m[1]);

        if ($tokens === [] || in_array('', $tokens, true)) {
            $this->err($file, $sheet, $r, 'Для ReOrder предложение записывается как {{слово}} {{слово}} … — пустых {{}} быть не должно.');

            return null;
        }

        if (trim((string) preg_replace('/\{\{.*?\}\}/su', '', $cell)) !== '') {
            $this->err($file, $sheet, $r, 'Для ReOrder вне {{…}} текста быть не должно.');

            return null;
        }

        return ['tokens' => $tokens];
    }

    /**
     * `LetterSpace`: `{{S<e>ve<n>}}` — the whole cell is one mask,
     * letters inside `<>` are the HIDDEN ones (§1.1).
     *
     * @return array<string, mixed>|null
     */
    private function letterPayload(string $cell, string $file, string $sheet, int $r): ?array
    {
        if (preg_match('/^\{\{(.+)\}\}$/su', trim($cell), $m) !== 1) {
            $this->err($file, $sheet, $r, 'Для LetterSpace ячейка должна быть одной маской вида {{S<e>ve<n>}}.');

            return null;
        }

        return ['mask' => $m[1]];
    }

    /**
     * `FillBlank`: `before{{correct~wrong~…}}after` — first option
     * correct, the split becomes before/after/answer/word_bank (§2).
     *
     * @return array<string, mixed>|null
     */
    private function blankPayload(string $cell, string $file, string $sheet, int $r): ?array
    {
        if (substr_count($cell, '{{') !== 1
            || preg_match('/^(.*)\{\{(.*?)\}\}(.*)$/su', $cell, $m) !== 1) {
            $this->err($file, $sheet, $r, 'Для FillBlank нужен ровно один блок {{правильный~неверный~…}} внутри предложения.');

            return null;
        }

        $bank = array_map('trim', explode('~', $m[2]));

        if (in_array('', $bank, true)) {
            $this->err($file, $sheet, $r, 'Пустой вариант внутри {{…~…}}.');

            return null;
        }

        return [
            'before'    => $m[1],
            'after'     => $m[3],
            'answer'    => [$bank[0]],
            'word_bank' => $bank,
        ];
    }

    /**
     * §2 leak fix — first insert only. The file lists the correct option
     * first (Option A), so a freshly imported multiple_choice question is
     * SHUFFLED and `answer` moved to the correct option's NEW index (a
     * random 0..n-1, not always 0). The correct answer then sits at a
     * random stored position, so serving each option its stored index `i`
     * — and naming its media `q{id}-opt{i}` — reveals nothing.
     *
     * @param array<string, mixed> $payload a normalised MC payload
     * @return array<string, mixed>
     */
    private static function shuffleMcOptions(array $payload): array
    {
        $options = array_values($payload['options'] ?? []);
        $count = count($options);

        if ($count < 2) {
            return $payload; // nothing to hide
        }

        $answer = (int) ($payload['answer'] ?? 0);
        $order = range(0, $count - 1);
        shuffle($order);

        $shuffled = [];
        $newAnswer = 0;

        foreach ($order as $newIndex => $originalIndex) {
            $shuffled[$newIndex] = $options[$originalIndex];

            if ($originalIndex === $answer) {
                $newAnswer = $newIndex;
            }
        }

        $payload['options'] = $shuffled;
        $payload['answer'] = $newAnswer;

        return $payload;
    }

    /**
     * §2 re-import stability. The stored option order is the shuffle chosen
     * at first insert; a re-import must not churn it (the answer index and
     * the `q{id}-opt{i}` media files hang off it). The file-order options
     * are re-mapped back onto the stored order by content identity, and
     * `answer` set to the stored position of the correct option — so an
     * unchanged re-import is a pure no-op. When the option SET genuinely
     * changed there is nothing to preserve, and the new options are dealt a
     * fresh shuffle instead.
     *
     * @param array<string, mixed> $new the freshly parsed payload (file order, answer 0)
     * @param array<string, mixed> $old the stored payload (shuffled order)
     * @return array<string, mixed>
     */
    private static function reconcileMcOrder(array $new, array $old): array
    {
        $newOptions = array_values($new['options'] ?? []);
        $oldOptions = array_values($old['options'] ?? []);

        // Only reconcile like-for-like; anything else is a genuine change.
        if (count($newOptions) < 2 || count($newOptions) !== count($oldOptions)) {
            return self::shuffleMcOptions($new);
        }

        $correctKey = self::mcOptionKey($newOptions[(int) ($new['answer'] ?? 0)] ?? null);

        // Bucket the new options by content identity so equal-keyed options
        // (there are none after the duplicate-option check, but be safe)
        // are consumed in order.
        $buckets = [];

        foreach ($newOptions as $option) {
            $buckets[self::mcOptionKey($option)][] = $option;
        }

        $reordered = [];

        foreach ($oldOptions as $oldOption) {
            $key = self::mcOptionKey($oldOption);

            if (empty($buckets[$key])) {
                // The stored order names an option the file no longer has —
                // the set changed. Re-shuffle the new options from scratch.
                return self::shuffleMcOptions($new);
            }

            $reordered[] = array_shift($buckets[$key]);
        }

        foreach ($buckets as $leftover) {
            if ($leftover !== []) {
                return self::shuffleMcOptions($new); // a new option had no home
            }
        }

        $answer = 0;

        foreach ($oldOptions as $position => $oldOption) {
            if (self::mcOptionKey($oldOption) === $correctKey) {
                $answer = $position;
                break;
            }
        }

        $new['options'] = $reordered;
        $new['answer'] = $answer;

        return $new;
    }

    /**
     * The content identity of one MC option — its text, or its media note
     * — ignoring the uploaded media_path (carried separately). Two options
     * are "the same option" when their keys match.
     */
    private static function mcOptionKey(mixed $option): string
    {
        if (is_array($option)) {
            foreach (['text', 'audio_note', 'image_note'] as $member) {
                if (isset($option[$member])) {
                    return $member . ':' . (string) $option[$member];
                }
            }
        }

        return 'text:' . (string) $option;
    }

    /**
     * Uploaded media survives a re-import as long as its note did not
     * change: for every audio/image part, the old payload's media_path is
     * carried over when the same-position part carries the same note.
     *
     * @param array<string, mixed> $new
     * @param array<string, mixed> $old
     * @return array<string, mixed>
     */
    private static function carryMedia(array $new, array $old): array
    {
        if (isset($new['stem']) && is_array($new['stem'])) {
            $new['stem'] = self::carryPart($new['stem'], $old['stem'] ?? null);
        }

        foreach ($new['options'] ?? [] as $i => $part) {
            if (is_array($part)) {
                $new['options'][$i] = self::carryPart($part, $old['options'][$i] ?? null);
            }
        }

        return $new;
    }

    /** @param array<string, mixed> $part */
    private static function carryPart(array $part, mixed $old): array
    {
        foreach (['audio_note', 'image_note'] as $kind) {
            if (isset($part[$kind]) && is_array($old)
                && ($old[$kind] ?? null) === $part[$kind]
                && !empty($old['media_path'])) {
                $part['media_path'] = $old['media_path'];
            }
        }

        return $part;
    }

    /**
     * Every audio/image part still lacking an uploaded file, for the
     * response's upload queue. A question with a pending part is
     * auto-hidden from students until the file arrives (§3), so the
     * superadmin needs the full list.
     *
     * @param array<string, mixed> $payload
     */
    private function collectMediaPending(string $code, array $payload): void
    {
        $parts = [];

        if (isset($payload['stem']) && is_array($payload['stem'])) {
            $parts['stem'] = $payload['stem'];
        }

        foreach ($payload['options'] ?? [] as $i => $part) {
            if (is_array($part)) {
                $parts['opt' . $i] = $part;
            }
        }

        foreach ($parts as $name => $part) {
            $note = $part['audio_note'] ?? $part['image_note'] ?? null;

            if ($note !== null && empty($part['media_path'])) {
                $this->mediaPending[] = [
                    'external_code' => $code,
                    'part'          => $name,
                    'note'          => (string) $note,
                ];
            }
        }
    }

    // --------------------------------------------------------- wordlist

    /** @param list<list<string>> $rows */
    private function wordlistSheet(string $file, string $sheet, array $rows): void
    {
        $resolved = $this->header($file, $sheet, $rows, self::WORDLIST_COLUMNS, self::WORDLIST_REQUIRED, 'word id');

        if ($resolved === null) {
            return;
        }

        [$cols, $headerRow] = $resolved;

        foreach ($rows as $i => $row) {
            if ($i <= $headerRow || array_filter($row, static fn (string $c): bool => $c !== '') === []) {
                continue;
            }

            $this->wordlistRow($file, $sheet, self::cells($cols, $row), $i + 1);
        }
    }

    /** @param array<string, string> $c */
    private function wordlistRow(string $file, string $sheet, array $c, int $r): void
    {
        if (($code = $this->externalCode($c['wid'], 'Word ID', $this->seenWordCodes, $file, $sheet, $r)) === null) {
            return;
        }

        $wordType = match (self::norm($c['wtype'] ?? '')) {
            'word', '' => 'word',
            'phrase'   => 'phrase',
            default    => null,
        };

        if ($wordType === null) {
            $this->err($file, $sheet, $r, "Type должен быть Word или Phrase, не «{$c['wtype']}».");

            return;
        }

        if ($c['en'] === '' || $c['tk'] === '' || $c['ru'] === '') {
            $this->err($file, $sheet, $r, 'Нужны слово (English) и оба перевода (Turkmen, Russian).');

            return;
        }

        if (($childId = $this->contextChild($c, $file, $sheet, $r)) === null) {
            return;
        }

        if (($sectionId = $this->sectionOfType($childId, Section::TYPE_VOCABULARY, $file, $sheet, $r)) === null) {
            return;
        }

        // Word rows share the vocabulary section with question rows but
        // order among themselves — a separate counter keyed off the real
        // one would let questions and words fight over sort_order.
        $sortKey = -$sectionId; // negative = the word counter of this section
        $sort = $this->sortNext[$sortKey] = ($this->sortNext[$sortKey] ?? 0) + 1;

        $fields = [
            'section_id'     => $sectionId,
            'category'       => mb_substr($c['category'] ?? '', 0, 120) ?: null,
            'word_type'      => $wordType,
            'term_en'        => mb_substr($c['en'], 0, 160),
            'translation_tk' => mb_substr($c['tk'], 0, 255),
            'translation_ru' => mb_substr($c['ru'], 0, 255),
            'sort_order'     => $sort,
        ];

        $existing = Capsule::table('vocabulary_items')->where('external_code', $code)->first();

        if ($existing === null) {
            Capsule::table('vocabulary_items')->insert($fields + [
                'external_code' => $code,
                'created_at'    => $this->now,
                'updated_at'    => $this->now,
            ]);
            $this->created['vocabulary_items']++;
            $this->touched[$sectionId] = true;

            return;
        }

        $changed = [];

        foreach ($fields as $column => $value) {
            $current = in_array($column, ['section_id', 'sort_order'], true)
                ? (int) $existing->{$column}
                : $existing->{$column};

            if ($value !== $current) {
                $changed[$column] = $value;
            }
        }

        if ($changed !== []) {
            $changed['updated_at'] = $this->now;
            Capsule::table('vocabulary_items')->where('id', $existing->id)->update($changed);
            $this->updated['vocabulary_items']++;
            $this->touched[$sectionId] = true;
            $this->touched[(int) $existing->section_id] = true;
        }
    }

    // --------------------------------------------------------- unit quiz

    /**
     * The UnitQuiz inventory (§1.3): a title row naming `UNIT <n><code>`,
     * then Skill / Item Count / Unit Quiz Target rows. TOTAL and the
     * "Quiz scoring" block are informational. One sheet may carry several
     * blocks (the export writes one per child unit in scope).
     *
     * @param list<list<string>> $rows
     */
    private function quizSheet(string $file, string $sheet, array $rows): void
    {
        $unit = null;    // array{no: int, code: string, row: int}
        $targets = [];   // skill => int

        $apply = function () use ($file, $sheet, &$unit, &$targets): void {
            if ($unit !== null) {
                $this->applyQuizBlock($file, $sheet, $unit, $targets);
            }

            $unit = null;
            $targets = [];
        };

        foreach ($rows as $i => $row) {
            $first = self::norm($row[0] ?? '');

            // "… UNIT 1A …" title row. The header's own "Unit Quiz
            // Target" cell never matches — the pattern wants digits.
            if (preg_match('/\bUNIT\s+(\d+)\s*([A-Za-z]+)\b/iu', implode(' ', $row), $m) === 1
                && $first !== 'skill') {
                $apply();
                $unit = ['no' => (int) $m[1], 'code' => mb_strtoupper($m[2]), 'row' => $i + 1];
                continue;
            }

            if (!array_key_exists($first, self::SKILLS)) {
                continue; // header, TOTAL, "Quiz scoring", spacer rows
            }

            $raw = trim($row[2] ?? '');

            if (preg_match('/^\d+$/', $raw) !== 1 || (int) $raw < 1 || (int) $raw > 255) {
                $this->err($file, $sheet, $i + 1, "Unit Quiz Target для «{$row[0]}» должен быть целым числом от 1 до 255.");
                continue;
            }

            if ($unit === null) {
                $this->err($file, $sheet, $i + 1, 'Строка целей встретилась до заголовка «… UNIT <номер><код> …» — юнит неизвестен.');
                continue;
            }

            $targets[$first] = (int) $raw;
        }

        $apply();
    }

    /**
     * @param array{no: int, code: string, row: int} $unit
     * @param array<string, int> $targets
     */
    private function applyQuizBlock(string $file, string $sheet, array $unit, array $targets): void
    {
        if ($targets === []) {
            $this->err($file, $sheet, $unit['row'], "Для UNIT {$unit['no']}{$unit['code']} нет строк Vocabulary/Grammar/Listening с целями.");

            return;
        }

        // The sheet carries no Level column — the unit is addressed by
        // number + code alone, so it must be unambiguous across levels.
        $children = Capsule::table('unit_sections as cu')
            ->join('units as u', 'u.id', '=', 'cu.unit_id')
            ->where('u.number', $unit['no'])
            ->whereRaw('UPPER(cu.code) = ?', [$unit['code']])
            ->orderBy('cu.id')
            ->get(['cu.id', 'cu.quiz_target_vocabulary', 'cu.quiz_target_grammar', 'cu.quiz_target_listening']);

        if ($children->isEmpty()) {
            $this->err($file, $sheet, $unit['row'], "Юнит {$unit['no']}{$unit['code']} не найден — загрузите UnitQuiz вместе с файлами вопросов или создайте юнит в панели.");

            return;
        }

        if ($children->count() > 1) {
            $this->err($file, $sheet, $unit['row'], "Юнит {$unit['no']}{$unit['code']} есть в нескольких уровнях — UnitQuiz не может выбрать между ними.");

            return;
        }

        $child = $children->first();
        $childId = (int) $child->id;

        $fields = [];

        foreach ($targets as $skill => $target) {
            $column = 'quiz_target_' . $skill;
            $current = $child->{$column} === null ? null : (int) $child->{$column};

            if ($current !== $target) {
                $fields[$column] = $target;
            }
        }

        if ($fields !== []) {
            Capsule::table('unit_sections')->where('id', $childId)->update($fields);
            $this->updated['quiz_targets']++;
        }

        // The quiz needs a section to live in — created draft, published
        // deliberately in the panel like everything else.
        if (($quizSectionId = $this->sectionOfType($childId, Section::TYPE_QUIZ, $file, $sheet, $unit['row'])) === null) {
            return;
        }

        // FR-14.3: the fixed draw is a cached random choice. It re-draws
        // lazily when the targets changed or this import rewrote the
        // unit's content; an untouched re-import keeps the set stable so
        // students do not get a reshuffled quiz for no reason.
        $contentChanged = $this->touched !== []
            && Capsule::table('sections')
                ->whereIn('id', array_keys($this->touched))
                ->where('unit_section_id', $childId)
                ->exists();

        if ($fields !== [] || $contentChanged) {
            Capsule::table('quiz_draws')->where('unit_section_id', $childId)->delete();
        }

        if ($fields !== []) {
            // New targets = a new quiz — behind the publish gate with the
            // rest of the imported content (FR-13.20).
            $this->touched[$quizSectionId] = true;
        }
    }

    // ------------------------------------------------------------ hierarchy

    /**
     * The child unit a row's Level / Unit / Lesson columns point at.
     * The level must exist (§1.1 — "error if no match"); the parent unit
     * and child unit are created when missing.
     *
     * @param array<string, string> $c
     */
    private function contextChild(array $c, string $file, string $sheet, int $r): ?int
    {
        if (($levelId = $this->matchLevel($c['level'])) === null) {
            $this->err($file, $sheet, $r, "Уровень «{$c['level']}» не найден — создайте его в панели (совпадает по полному названию или любой половине через «/»).");

            return null;
        }

        // 65535 = the units.number column's SMALLINT UNSIGNED ceiling.
        if (preg_match('/^\d+$/', $c['unit']) !== 1
            || (int) $c['unit'] < 1 || (int) $c['unit'] > 65535) {
            $this->err($file, $sheet, $r, "Неверный номер юнита «{$c['unit']}».");

            return null;
        }

        $number = (int) $c['unit'];
        $lesson = $c['lesson'];

        // "1A" = unit 1 + child code A: the Lesson minus the unit number.
        if (!str_starts_with($lesson, (string) $number)
            || ($code = trim(mb_substr($lesson, strlen((string) $number)))) === ''
            || mb_strlen($code) > 8) {
            $this->err($file, $sheet, $r, "Lesson «{$lesson}» должен быть номером юнита плюс код (например, {$number}A).");

            return null;
        }

        return $this->ensureChildUnit($this->ensureUnit($levelId, $number), $code);
    }

    /**
     * `"Beginner / A1"` matches a level whose name equals the whole cell
     * or either half, trimmed and case-insensitive (§1.1). The full name
     * is tried first so a level literally named "Beginner / A1" wins over
     * one named "Beginner".
     */
    private function matchLevel(string $cell): ?int
    {
        $key = mb_strtolower(trim($cell));

        if (array_key_exists($key, $this->levelCache)) {
            return $this->levelCache[$key];
        }

        $candidates = [trim($cell)];

        foreach (explode('/', $cell) as $half) {
            $candidates[] = trim($half);
        }

        $levels = Capsule::table('levels')
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'name']);

        $found = null;

        foreach (array_unique(array_filter($candidates)) as $candidate) {
            foreach ($levels as $level) {
                if (mb_strtolower(trim((string) $level->name)) === mb_strtolower($candidate)) {
                    $found = (int) $level->id;
                    break 2;
                }
            }
        }

        return $this->levelCache[$key] = $found;
    }

    private function ensureUnit(int $levelId, int $number): int
    {
        $unit = Capsule::table('units')->where('level_id', $levelId)->where('number', $number)->first();

        if ($unit !== null) {
            return (int) $unit->id;
        }

        $this->created['units']++;

        return (int) Capsule::table('units')->insertGetId([
            'level_id'   => $levelId,
            'number'     => $number,
            'sort_order' => $number, // teaching order = the client's numbering
        ]);
    }

    private function ensureChildUnit(int $unitId, string $code): int
    {
        $child = Capsule::table('unit_sections')->where('unit_id', $unitId)->where('code', $code)->first();

        if ($child !== null) {
            return (int) $child->id;
        }

        // level_position is the teaching order across the whole level: a
        // new child unit goes to the end, the same rule the panel applies.
        $levelId = (int) Capsule::table('units')->where('id', $unitId)->value('level_id');
        $position = (int) Capsule::table('unit_sections')
            ->join('units', 'units.id', '=', 'unit_sections.unit_id')
            ->where('units.level_id', $levelId)
            ->max('unit_sections.level_position') + 1;

        $this->created['child_units']++;

        return (int) Capsule::table('unit_sections')->insertGetId([
            'unit_id'        => $unitId,
            'code'           => $code,
            'sort_order'     => (int) Capsule::table('unit_sections')->where('unit_id', $unitId)->count() + 1,
            'level_position' => $position,
        ]);
    }

    /**
     * The child unit's single section of one type — created draft and
     * untitled when absent, refused when the type appears twice (the
     * file's natural key cannot address one of two).
     */
    private function sectionOfType(int $childId, string $type, string $file, string $sheet, int $r): ?int
    {
        $ids = Capsule::table('sections')
            ->where('unit_section_id', $childId)->where('type', $type)
            ->orderBy('id')->pluck('id')->all();

        if (count($ids) > 1) {
            $this->err($file, $sheet, $r, "В юните несколько разделов типа «{$type}» — импорт не может выбрать между ними.");

            return null;
        }

        if ($ids !== []) {
            return (int) $ids[0];
        }

        $this->created['sections']++;

        return (int) Capsule::table('sections')->insertGetId([
            'unit_section_id' => $childId,
            'type'            => $type,
            'status'          => Section::STATUS_DRAFT, // FR-13.20
            'sort_order'      => (int) Capsule::table('sections')
                ->where('unit_section_id', $childId)->max('sort_order') + 1,
            'created_at'      => $this->now,
            'updated_at'      => $this->now,
        ]);
    }

    /**
     * The section's one exercise set per type, created on demand. The v2
     * files know nothing of sets, so the set is born PUBLISHED: the
     * section (forced draft, FR-13.20) is the single visibility gate the
     * panel operates (FR-13.3), and a second, invisible per-set gate
     * would leave published sections silently empty.
     */
    private function setOfType(int $sectionId, string $type, string $file, string $sheet, int $r): ?int
    {
        $ids = Capsule::table('exercise_sets')
            ->where('section_id', $sectionId)->where('type', $type)
            ->orderBy('id')->pluck('id')->all();

        if (count($ids) > 1) {
            $this->err($file, $sheet, $r, "В разделе несколько упражнений типа «{$type}» — импорт не может выбрать между ними.");

            return null;
        }

        if ($ids !== []) {
            return (int) $ids[0];
        }

        [$titleTk, $titleRu] = self::SET_TITLES[$type];

        $this->created['exercise_sets']++;

        return (int) Capsule::table('exercise_sets')->insertGetId([
            'section_id' => $sectionId,
            'type'       => $type,
            'title_tk'   => $titleTk,
            'title_ru'   => $titleRu,
            // The CHECK constraints want these null on every other type;
            // the defaults match the panel's (saveSet).
            'input_mode' => $type === ExerciseSet::TYPE_FILL_BLANK ? 'word_bank' : null,
            'pair_mode'  => null,
            'status'     => ExerciseSet::STATUS_PUBLISHED,
            'sort_order' => (int) Capsule::table('exercise_sets')
                ->where('section_id', $sectionId)->max('sort_order') + 1,
            'created_at' => $this->now,
            'updated_at' => $this->now,
        ]);
    }

    // -------------------------------------------------------------- helpers

    /** Question Type column -> stored exercise set type (§1.1). */
    private static function setType(string $value): ?string
    {
        $value = self::norm($value);

        return match (true) {
            $value === 'test'                             => ExerciseSet::TYPE_MULTIPLE_CHOICE,
            // "Practical Dialogue → Detail / Meaning" — listening
            // variants that behave exactly as Test.
            str_starts_with($value, 'practical dialogue') => ExerciseSet::TYPE_MULTIPLE_CHOICE,
            $value === 'reorder'                          => ExerciseSet::TYPE_REORDER,
            $value === 'letterspace'                      => ExerciseSet::TYPE_FILL_LETTER_SPACE,
            $value === 'fillblank'                        => ExerciseSet::TYPE_FILL_BLANK,
            default                                       => null,
        };
    }

    /**
     * A validated external code, unique within this import; null with the
     * error recorded otherwise.
     *
     * @param array<string, int> $seen lower(code) => Excel row that claimed it
     */
    private function externalCode(string $raw, string $label, array &$seen, string $file, string $sheet, int $r): ?string
    {
        $code = trim($raw);

        if ($code === '' || mb_strlen($code) > 24) {
            $this->err($file, $sheet, $r, "{$label} обязателен (до 24 символов).");

            return null;
        }

        $key = mb_strtolower($code);

        if (isset($seen[$key])) {
            $this->err($file, $sheet, $r, "{$label} «{$code}» уже встречался в этом импорте (строка {$seen[$key]}).");

            return null;
        }

        $seen[$key] = $r;

        return $code;
    }

    /**
     * The editor's payload gate (QuestionPayload), rephrased as a
     * row-level error: the normalised payload, or null with the refusal
     * recorded against its Excel row.
     *
     * @param array<mixed> $payload
     * @return array<string, mixed>|null
     */
    private function validPayload(string $type, array $payload, string $file, string $sheet, int $r): ?array
    {
        try {
            QuestionPayload::assert($type, $payload);
        } catch (ApiException $e) {
            $this->err($file, $sheet, $r, $e->messageRu);

            return null;
        }

        return QuestionPayload::normalise($type, $payload);
    }

    private function err(string $file, string $sheet, int $row, string $messageRu): void
    {
        $this->errors[] = ['file' => $file, 'sheet' => $sheet, 'row' => $row, 'message_ru' => $messageRu];
    }

    /** Lowercased, trimmed, inner whitespace collapsed — header/enum matching. */
    private static function norm(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }
}
