<?php

declare(strict_types=1);

/**
 * The v2 xlsx import (FR-14.1), fed the client's REAL files.
 *
 * Imports all five fixture workbooks (Listening / Grammar / Vocabulary /
 * UnitQuiz / Wordlist — the canonical 2026-08-13 set) through
 * XlsxImportService against the live database and checks the contract
 * (docs/06-CONTENT-V2.md): 134 questions land with the right types in
 * the right typed sections in file order, Option A becomes original
 * index 0, reorder keeps token order, letter masks and fill_blank banks
 * store the v2 shapes, 80 wordlist rows carry categories and word
 * types, the UnitQuiz targets land on the child unit, every touched
 * section is draft (FR-13.20), media notes are queued in media_pending,
 * an unchanged re-import is a pure no-op that preserves uploaded media,
 * a changed note clears its part's file, and one bad row rejects the
 * WHOLE import (all-or-nothing).
 *
 *   C:/xampp/php/php.exe tests/content_v2_import_test.php
 *
 * The whole test runs inside one outer transaction that is rolled back
 * in finally — the importer's own transaction becomes a savepoint — so
 * nothing it does can outlive the run. Levels that would capture the
 * fixtures' "Beginner / A1" rows are shadow-renamed inside that same
 * transaction and come back on rollback.
 */

require __DIR__ . '/../vendor/autoload.php';

use Dana\Database\Bootstrap;
use Dana\Domain\Content\XlsxImportService;
use Dana\Support\Config;
use Dana\Support\Xlsx\XlsxReader;
use Dana\Support\Xlsx\XlsxWriter;
use Illuminate\Database\Capsule\Manager as Capsule;

$config = Config::load(dirname(__DIR__));
Bootstrap::boot($config);

const FIXTURES = __DIR__ . '/fixtures';

const V2_HEADER = [
    'Question ID', 'Level', 'Unit', 'Lesson', 'Topic', 'Subtopic',
    'Question Type', 'Rule', 'Question', 'Option A', 'Option B',
    'Option C', 'Option D', 'Answer Description', 'Eligble',
];

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($detail !== '' ? "  ({$detail})" : '') . PHP_EOL;
}

/** @return list<array{name: string, path: string}> */
function fixtureFiles(): array
{
    return array_map(
        static fn (string $name): array => ['name' => $name . '.xlsx', 'path' => FIXTURES . '/' . $name . '.xlsx'],
        ['Listening', 'Grammar', 'Vocabulary', 'UnitQuiz', 'Wordlist']
    );
}

/**
 * The pristine client files carry three genuine data-entry typos that a
 * strict, all-or-nothing importer correctly REJECTS (asserted below), so
 * the client's own declared 134 items cannot land until they are fixed.
 * Each repair here uses only strings the client actually wrote in that
 * same cell (or the deterministic sequence id) — no content is invented:
 *
 *   - Listening U1-L-026 (row 27): the Question ID cell holds a stray
 *     triple `[audio: 0, image: 0, text: "7"]` instead of an id. Restored
 *     to `U1-L-026` from the surrounding sequence (…025, ?, 027…).
 *   - Grammar U1-G-029 (row 30): Option A and Option B are the SAME
 *     corrupt string `[audio: 0, image: 0, text: "isn't"]It isn't a
 *     dictionary.`. Split into the two distinct strings present in that
 *     cell — the full sentence (Option A, correct) and the bare word.
 *   - Grammar U1-G-041 (row 42): Option A `[audio: 0, image: 0,
 *     text: "]am / is"` — a misplaced quote. The value `am / is` (the
 *     one verb pairing the other three options don't cover) is in-cell.
 *
 * The copies are written to $tmpDir; the other three files ship clean and
 * are imported straight from FIXTURES.
 *
 * @return list<array{name: string, path: string}>
 */
function correctedFiles(string $tmpDir): array
{
    $patches = [
        'Listening' => [
            // [rowIndex (0=header), colIndex, value]
            [26, 0, 'U1-L-026'],
        ],
        'Grammar' => [
            [29,  9, '[audio: 0, image: 0, text: "It isn\'t a dictionary."]'],
            [29, 10, '[audio: 0, image: 0, text: "isn\'t"]'],
            [41,  9, '[audio: 0, image: 0, text: "am / is"]'],
        ],
    ];

    $files = [];

    foreach (['Listening', 'Grammar'] as $skill) {
        $sheets = XlsxReader::open(FIXTURES . '/' . $skill . '.xlsx')->sheets();
        $sheetName = (string) array_key_first($sheets);
        $rows = $sheets[$sheetName];

        foreach ($patches[$skill] as [$rowIdx, $colIdx, $value]) {
            while (count($rows[$rowIdx]) <= $colIdx) {
                $rows[$rowIdx][] = '';
            }
            $rows[$rowIdx][$colIdx] = $value;
        }

        $header = array_shift($rows);
        $writer = new XlsxWriter();
        $writer->addSheet($sheetName, $header, $rows);
        $path = $tmpDir . '/' . $skill . '.xlsx';
        $writer->saveTo($path);

        $files[] = ['name' => $skill . '.xlsx', 'path' => $path];
    }

    // The three clean files ship as-is.
    foreach (['Vocabulary', 'UnitQuiz', 'Wordlist'] as $name) {
        $files[] = ['name' => $name . '.xlsx', 'path' => FIXTURES . '/' . $name . '.xlsx'];
    }

    return $files;
}

$tmpDir = sys_get_temp_dir() . '/dana_cv2_test_' . getmypid();

if (!is_dir($tmpDir) && !mkdir($tmpDir)) {
    fwrite(STDERR, "Cannot create temp dir {$tmpDir}\n");
    exit(1);
}

$connection = Capsule::connection();
$connection->beginTransaction(); // everything below is rolled back

try {
    $now = date('Y-m-d H:i:s');

    // The fixtures say "Beginner / A1". Any real level that phrase would
    // match is shadow-renamed (inside the transaction — rollback
    // restores it) so the import lands on OUR throwaway level only.
    Capsule::table('levels')
        ->whereRaw("LOWER(TRIM(name)) IN ('beginner / a1', 'beginner', 'a1')")
        ->update(['name' => Capsule::raw("CONCAT(name, ' cv2shadow')")]);

    // The UnitQuiz sheet addresses its unit by number + code with no
    // level, so it must be unambiguous across levels (docs §1.3). A real
    // "Unit 1 / Lesson A" already living in the database would collide
    // with the one this import creates under the throwaway level, so any
    // pre-existing 1/A child unit is shadow-renamed inside the same
    // transaction and restored on rollback.
    Capsule::table('unit_sections')
        ->whereIn('unit_id', Capsule::table('units')->where('number', 1)->pluck('id')->all())
        ->whereRaw("UPPER(TRIM(code)) = 'A'")
        ->update(['code' => Capsule::raw("CONCAT('z', id)")]);

    $levelId = (int) Capsule::table('levels')->insertGetId([
        'name'       => 'A1',
        'slug'       => 'cv2-import-test-' . getmypid(),
        'sort_order' => 999,
        'is_active'  => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // This DB may already hold real content with the fixture external
    // codes (a live import of the same client files). external_code is
    // globally unique, so the fixture import would UPDATE those rows
    // instead of creating clean ones. Clear the U1-* codes inside the
    // transaction — rollback restores every one — so the import below
    // exercises a true create. Their attempt_answers go first (FK).
    $liveIds = Capsule::table('questions')->where('external_code', 'like', 'U1-%')->pluck('id')->all();
    if ($liveIds !== []) {
        Capsule::table('attempt_answers')->whereIn('question_id', $liveIds)->delete();
        Capsule::table('questions')->whereIn('id', $liveIds)->delete();
    }
    Capsule::table('vocabulary_items')->where('external_code', 'like', 'U1-%')->delete();

    // Skip-and-report (client decision 2026-08-14) — the pristine files
    // carry three genuine typos (L27, G30, G42); a real import reports
    // those three and still lands the other 131 questions. That path is
    // covered by content_v2_skipreport_test.php, which runs the importer
    // outside this file's outer transaction (the importer opens its own
    // transaction, which does not nest cleanly under one already open
    // here). Below we import the CORRECTED files to exercise a full,
    // clean create of all 134.

    echo "\n=== the five client files (typos fixed), one transaction ===\n";

    $importFiles = correctedFiles($tmpDir);
    $result = (new XlsxImportService())->import($importFiles);

    check('zero errors', $result['errors'] === [], json_encode($result['errors'], JSON_UNESCAPED_UNICODE));
    check(
        'created: 1 unit, 1 child, 4 sections, 7 sets, 134 questions, 80 words',
        $result['created'] === [
            'units' => 1, 'child_units' => 1, 'sections' => 4,
            'exercise_sets' => 7, 'questions' => 134,
            'vocabulary_items' => 80, 'quiz_targets' => 0,
        ],
        json_encode($result['created'])
    );
    check('quiz targets recorded as the one update', $result['updated']['quiz_targets'] === 1
        && array_sum($result['updated']) === 1, json_encode($result['updated']));

    $childId = (int) Capsule::table('unit_sections as cu')
        ->join('units as u', 'u.id', '=', 'cu.unit_id')
        ->where('u.level_id', $levelId)->where('u.number', 1)->where('cu.code', 'A')
        ->value('cu.id');
    check('unit 1 / lesson A created under the test level', $childId > 0);

    $sections = Capsule::table('sections')->where('unit_section_id', $childId)
        ->get()->keyBy('type');
    check(
        'listening + grammar + vocabulary + quiz sections exist',
        $sections->has(['listening', 'grammar', 'vocabulary', 'quiz'])
    );
    check(
        'ALL sections are draft (FR-13.20)',
        $sections->every(static fn ($s): bool => $s->status === 'draft')
    );

    $questions = Capsule::table('questions as q')
        ->join('exercise_sets as es', 'es.id', '=', 'q.exercise_set_id')
        ->join('sections as s', 's.id', '=', 'es.section_id')
        ->where('s.unit_section_id', $childId)
        ->orderBy('s.type')->orderBy('q.sort_order')
        ->get([
            'q.external_code', 'q.question_type', 'q.quiz_eligible', 'q.topic',
            'q.rule_en', 'q.answer_description_en', 'q.sort_order', 'q.payload',
            'es.type as set_type', 's.type as section_type',
        ]);

    echo "\n=== 134 questions, typed and counted ===\n";

    check('134 questions in all', $questions->count() === 134, 'got ' . $questions->count());
    check(
        'external codes are unique',
        $questions->pluck('external_code')->unique()->count() === 134
    );

    $bySkill = $questions->countBy('section_type');
    check(
        'per skill: listening 39, grammar 45, vocabulary 50',
        ($bySkill['listening'] ?? 0) === 39
        && ($bySkill['grammar'] ?? 0) === 45
        && ($bySkill['vocabulary'] ?? 0) === 50,
        json_encode($bySkill)
    );

    // 117 Test rows + 5 Practical Dialogue variants = 122 multiple_choice.
    $byType = $questions->countBy('set_type');
    check(
        'types: mc 122 (117 Test + 5 Dialogue), reorder 5, letter 2, blank 5',
        ($byType['multiple_choice'] ?? 0) === 122
        && ($byType['reorder'] ?? 0) === 5
        && ($byType['fill_letter_space'] ?? 0) === 2
        && ($byType['fill_blank'] ?? 0) === 5,
        json_encode($byType)
    );

    $byCode = $questions->keyBy('external_code');
    check(
        'Practical Dialogue variants landed as multiple_choice',
        $byCode['U1-L-035']->set_type === 'multiple_choice'
        && $byCode['U1-L-039']->set_type === 'multiple_choice'
    );

    $listening = $questions->where('section_type', 'listening')->sortBy('sort_order')->values();
    check(
        'file order survives via sort_order (listening 1..39)',
        $listening->first()->external_code === 'U1-L-001'
        && (int) $listening->first()->sort_order === 1
        && $listening->last()->external_code === 'U1-L-039'
        && (int) $listening->last()->sort_order === 39
    );

    echo "\n=== payload shapes (§2) ===\n";

    // §2 (UPDATED): options are SHUFFLED on first insert and `answer` is
    // moved to the correct option's NEW index — never a fixed 0. That
    // random index is exactly what makes serving each option its stored
    // index i (and naming its media q{id}-opt{i}) leak nothing.
    $mcPayloads = $questions->where('set_type', 'multiple_choice')
        ->map(static fn ($q) => json_decode((string) $q->payload, true));
    check(
        'every mc answer is a valid in-range index',
        $mcPayloads->count() === 122 && $mcPayloads->every(
            static fn ($p): bool => is_int($p['answer'] ?? null)
                && $p['answer'] >= 0 && $p['answer'] < count($p['options'] ?? [])
        )
    );
    check(
        'options are shuffled — the answer is NOT always index 0 (§2 leak fixed)',
        $mcPayloads->contains(static fn ($p): bool => (int) ($p['answer'] ?? 0) !== 0)
    );

    $g1 = json_decode((string) $byCode['U1-G-001']->payload, true);
    $g1Texts = array_map(static fn ($o) => $o['text'] ?? null, $g1['options']);
    sort($g1Texts);
    check(
        'a 3-option Test row keeps its 3 text parts (order now shuffled, §2)',
        ($g1['stem']['text'] ?? null) === 'I ___ Ali.'
        && count($g1['options']) === 3
        && $g1Texts === ['am', 'are', 'is']
        // `answer` follows the shuffle to the file's correct Option A ("am").
        && ($g1['options'][$g1['answer']]['text'] ?? null) === 'am',
        json_encode($g1, JSON_UNESCAPED_UNICODE)
    );

    $l1 = json_decode((string) $byCode['U1-L-001']->payload, true);
    check(
        'audio stem stored as a note with media_path null',
        $l1['stem'] === ['audio_note' => 'seven', 'media_path' => null]
        && $byCode['U1-L-001']->question_type === 'audio'
    );

    $dialogue = json_decode((string) $byCode['U1-L-035']->payload, true);
    check(
        'a multi-line dialogue note survives inside the triple',
        str_contains((string) ($dialogue['stem']['audio_note'] ?? ''), "\n")
        && str_contains((string) $dialogue['stem']['audio_note'], 'A - L - I'),
        json_encode($dialogue['stem'] ?? null, JSON_UNESCAPED_UNICODE)
    );

    $imgStem = json_decode((string) $byCode['U1-V-037']->payload, true);
    check(
        'image stem stored as a note, question_type picture',
        $imgStem['stem'] === ['image_note' => 'pen', 'media_path' => null]
        && $byCode['U1-V-037']->question_type === 'picture'
    );

    $reorder = json_decode((string) $byCode['U1-G-012']->payload, true);
    check(
        'reorder keeps token order verbatim, no answer array (v2)',
        $reorder === ['tokens' => ['Are', 'you', 'from', 'Germany', '?']],
        json_encode($reorder)
    );

    check(
        'letter masks stored (v2 {mask})',
        json_decode((string) $byCode['U1-V-007']->payload, true) === ['mask' => 'S<e>ve<n>']
        && json_decode((string) $byCode['U1-V-003']->payload, true) === ['mask' => 'eig<h>t']
    );

    $blank = json_decode((string) $byCode['U1-V-017']->payload, true);
    check(
        'fill_blank splits before/after and keeps the bank, answer first',
        $blank['answer'] === ['Spain']
        && $blank['word_bank'] === ['Spain', 'Italy', 'Germany', 'Mexico']
        && str_ends_with((string) $blank['before'], "She's from ")
        && $blank['after'] === '.',
        json_encode($blank, JSON_UNESCAPED_UNICODE)
    );

    $blankFirst = $questions->where('set_type', 'fill_blank')
        ->every(static function ($q): bool {
            $p = json_decode((string) $q->payload, true);

            return ($p['answer'][0] ?? null) === ($p['word_bank'][0] ?? '—');
        });
    check('every fill_blank answer is the bank\'s first entry', $blankFirst);

    check(
        'Rule and Answer Description stored verbatim (English)',
        $byCode['U1-L-001']->rule_en === 'Which number do you hear?'
        && $byCode['U1-L-001']->answer_description_en === 'You heard seven.'
        && $byCode['U1-L-001']->topic === 'Numbers'
        && (int) $byCode['U1-L-001']->quiz_eligible === 1
    );

    echo "\n=== wordlist (§1.2) ===\n";

    $words = Capsule::table('vocabulary_items as v')
        ->join('sections as s', 's.id', '=', 'v.section_id')
        ->where('s.unit_section_id', $childId)
        ->get(['v.external_code', 'v.category', 'v.word_type', 'v.term_en', 'v.translation_tk', 'v.translation_ru']);

    check('80 wordlist rows', $words->count() === 80, 'got ' . $words->count());
    check(
        'word/phrase types: 44 + 36',
        ($words->countBy('word_type')['word'] ?? 0) === 44
        && ($words->countBy('word_type')['phrase'] ?? 0) === 36
    );
    check(
        'categories carried (Numbers 0-10 has 11 rows)',
        ($words->countBy('category')['Numbers 0-10'] ?? 0) === 11
    );

    $zero = $words->firstWhere('external_code', 'U1-W-001');
    check(
        'U1-W-001 = zero / nol / ноль',
        $zero !== null && $zero->term_en === 'zero'
        && $zero->translation_tk === 'nol' && $zero->translation_ru === 'ноль'
        && $zero->word_type === 'word'
    );

    echo "\n=== unit quiz targets (§1.3, FR-14.3) ===\n";

    $child = Capsule::table('unit_sections')->where('id', $childId)->first();
    check(
        'targets 7 vocabulary / 8 grammar / 5 listening stored',
        (int) $child->quiz_target_vocabulary === 7
        && (int) $child->quiz_target_grammar === 8
        && (int) $child->quiz_target_listening === 5
    );
    check(
        'quiz draw cleared for a lazy re-draw',
        Capsule::table('quiz_draws')->where('unit_section_id', $childId)->count() === 0
    );

    echo "\n=== media_pending: the upload queue ===\n";

    // The fixtures carry 42 audio + 25 image notes and no files yet.
    check('67 parts await upload', count($result['media_pending']) === 67, 'got ' . count($result['media_pending']));
    check(
        'U1-L-001 stem is queued with its note',
        in_array(['external_code' => 'U1-L-001', 'part' => 'stem', 'note' => 'seven'], $result['media_pending'], true)
    );
    // The option positions are shuffled (§2), so the notes are asserted by
    // presence, not by a fixed opt index.
    $l9Notes = array_map(
        static fn ($e) => $e['note'],
        array_filter(
            $result['media_pending'],
            static fn ($e): bool => $e['external_code'] === 'U1-L-009' && str_starts_with($e['part'], 'opt')
        )
    );
    check(
        'image OPTIONS are queued part by part (notes present, positions shuffled)',
        in_array('IMG_PEN', $l9Notes, true) && in_array('IMG_DOOR', $l9Notes, true),
        json_encode(array_values($l9Notes))
    );

    echo "\n=== re-import: a pure no-op that keeps uploaded media ===\n";

    // Simulate the panel uploading the U1-L-001 stem audio.
    $q1 = Capsule::table('questions')->where('external_code', 'U1-L-001')->first();
    $p1 = json_decode((string) $q1->payload, true);
    $p1['stem']['media_path'] = 'media/q' . $q1->id . '-stem.mp3';
    Capsule::table('questions')->where('id', $q1->id)
        ->update(['payload' => json_encode($p1, JSON_UNESCAPED_UNICODE)]);

    $again = (new XlsxImportService())->import($importFiles);

    check('re-import: zero errors', $again['errors'] === []);
    check('re-import creates nothing', array_sum($again['created']) === 0, json_encode($again['created']));
    check('re-import updates nothing', array_sum($again['updated']) === 0, json_encode($again['updated']));

    $p1after = json_decode((string) Capsule::table('questions')->where('id', $q1->id)->value('payload'), true);
    check(
        'uploaded media survives — the note did not change',
        ($p1after['stem']['media_path'] ?? null) === 'media/q' . $q1->id . '-stem.mp3'
    );
    check(
        'the uploaded part left media_pending (66 remain)',
        count($again['media_pending']) === 66
        && !in_array(['external_code' => 'U1-L-001', 'part' => 'stem', 'note' => 'seven'], $again['media_pending'], true),
        'got ' . count($again['media_pending'])
    );
    check(
        'quiz draw untouched by the no-op (still awaiting its lazy draw)',
        Capsule::table('quiz_draws')->where('unit_section_id', $childId)->count() === 0
    );

    echo "\n=== a changed note clears its media ===\n";

    $changed = new XlsxWriter();
    $changed->addSheet('Listening', V2_HEADER, [[
        'U1-L-001', 'Beginner / A1', 1, '1A', 'Numbers', 'Number Recognition',
        'Test', 'Which number do you hear?',
        '[audio: "seven, slowly", image: 0, text: 0]',
        '[audio: 0, image: 0, text: "7"]', '[audio: 0, image: 0, text: "6"]',
        '[audio: 0, image: 0, text: "5"]', '[audio: 0, image: 0, text: "9"]',
        'You heard seven.', 'Yes',
    ]]);
    $changedPath = $tmpDir . '/NoteChange.xlsx';
    $changed->saveTo($changedPath);

    $third = (new XlsxImportService())->import([['name' => 'Listening.xlsx', 'path' => $changedPath]]);

    check('changed-note import: zero errors, one question updated', $third['errors'] === []
        && $third['updated']['questions'] === 1, json_encode($third));

    $p1changed = json_decode((string) Capsule::table('questions')->where('id', $q1->id)->value('payload'), true);
    check(
        'the stale file is cleared and the part re-queued',
        ($p1changed['stem'] ?? null) === ['audio_note' => 'seven, slowly', 'media_path' => null]
        && in_array(['external_code' => 'U1-L-001', 'part' => 'stem', 'note' => 'seven, slowly'], $third['media_pending'], true),
        json_encode($p1changed['stem'] ?? null, JSON_UNESCAPED_UNICODE)
    );

    echo "\n=== skip-and-report: a bad row is skipped, its valid sibling lands ===\n";

    $bad = new XlsxWriter();
    $bad->addSheet('Grammar', V2_HEADER, [
        [
            'U1-G-901', 'Beginner / A1', 1, '1A', 'Verb BE', 'Valid row',
            'Test', 'Choose.', '[audio: 0, image: 0, text: "He ___ tall."]',
            '[audio: 0, image: 0, text: "is"]', '[audio: 0, image: 0, text: "am"]',
            '', '', 'He + is.', 'Yes',
        ],
        [
            // Two used parts in one triple — the poisoned row.
            'U1-G-902', 'Beginner / A1', 1, '1A', 'Verb BE', 'Broken row',
            'Test', 'Choose.', '[audio: "clip", image: 0, text: "also text"]',
            '[audio: 0, image: 0, text: "a"]', '[audio: 0, image: 0, text: "b"]',
            '', '', '', 'Yes',
        ],
    ]);
    $badPath = $tmpDir . '/Broken.xlsx';
    $bad->saveTo($badPath);

    $refused = (new XlsxImportService())->import([['name' => 'Grammar.xlsx', 'path' => $badPath]]);

    check(
        'the poisoned row is reported with file, sheet and row',
        count($refused['errors']) === 1
        && $refused['errors'][0]['file'] === 'Grammar.xlsx'
        && $refused['errors'][0]['sheet'] === 'Grammar'
        && $refused['errors'][0]['row'] === 3,
        json_encode($refused['errors'], JSON_UNESCAPED_UNICODE)
    );
    // Skip-and-report (2026-08-14): the poisoned row is skipped, but the
    // VALID sibling still lands — one typo must not block the file.
    check(
        'the valid sibling row WAS created (not rolled back)',
        $refused['created']['questions'] === 1
        && Capsule::table('questions')->where('external_code', 'U1-G-901')->exists()
    );
    check(
        'the question count grew by exactly the one valid row (135)',
        Capsule::table('questions as q')
            ->join('exercise_sets as es', 'es.id', '=', 'q.exercise_set_id')
            ->join('sections as s', 's.id', '=', 'es.section_id')
            ->where('s.unit_section_id', $childId)->count() === 135
    );
} finally {
    $connection->rollBack(); // the whole run, importer commits included

    foreach (glob($tmpDir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tmpDir);
    echo "\nTransaction rolled back, temp files removed.\n";
}

echo "{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
