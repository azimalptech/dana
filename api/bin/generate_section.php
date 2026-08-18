<?php

declare(strict_types=1);

/**
 * Runs the content pipeline for one unit section.
 *
 *   php bin/generate_section.php --section=1 [--publish]
 *
 * Normally output stays in `draft` until a superadmin reviews it
 * (FR-4.17). --publish skips that gate; it exists only because the
 * review panel is not built yet, and should not survive into production.
 */

require __DIR__ . '/../vendor/autoload.php';

use Dana\Content\Llm\GeminiProvider;
use Dana\Content\Llm\LlmRateLimitException;
use Dana\Content\SectionGenerator;
use Dana\Database\Bootstrap;
use Dana\Domain\Models\ExerciseSet;
use Dana\Domain\Models\UnitSection;
use Dana\Support\Config;
use Dana\Support\LoggerFactory;
use Illuminate\Database\Capsule\Manager as Capsule;

$config = Config::load(dirname(__DIR__));
Bootstrap::boot($config);
$log = LoggerFactory::get($config, 'worker');

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/s', $arg, $m) === 1) {
        $args[$m[1]] = $m[2] ?? '1';
    }
}

$sectionId = (int) ($args['section'] ?? 0);
$publish = isset($args['publish']);
$status = $publish ? 'published' : 'draft';

$section = UnitSection::query()->with('unit')->find($sectionId);

if ($section === null) {
    fwrite(STDERR, "Section {$sectionId} not found.\n");
    exit(1);
}

$provider = new GeminiProvider(
    apiKey: (string) $config->get('GEMINI_API_KEY'),
    model: (string) $config->get('GEMINI_MODEL_GENERATE', 'gemini-3.5-flash'),
);

// G8 — a second model reviews every generated item. A cheaper model is
// enough: verifying an answer is easier than producing one.
$judge = new GeminiProvider(
    apiKey: (string) $config->get('GEMINI_API_KEY'),
    model: (string) $config->get('GEMINI_MODEL_JUDGE', 'gemini-3.5-flash-lite'),
);

$generator = new SectionGenerator($provider, $judge);
$now = date('Y-m-d H:i:s');
$label = $section->label();

echo "=== Section {$label} — {$section->title} ===\n";

$pool = $generator->buildPool($section);
echo 'Sentence pool: ' . count($pool) . " usable sentences from the mapped pages\n";

if ($pool === []) {
    fwrite(STDERR, "Nothing to generate from. Check the page mapping and OCR.\n");
    exit(1);
}

// ---- 1. vocabulary ---------------------------------------------------
// FR-6.2 makes vocabulary superadmin-authored. What the model produces
// here is a PROPOSAL drawn from the same pages; it is written so the
// section has content to show, and is meant to be reviewed.

$existingVocab = Capsule::table('vocabulary_items')->where('unit_section_id', $section->id)->count();

if ($existingVocab === 0) {
    echo "Proposing vocabulary ...\n";

    $vocabSchema = [
        'type'       => 'OBJECT',
        'properties' => ['items' => [
            'type'  => 'ARRAY',
            'items' => [
                'type'       => 'OBJECT',
                'properties' => [
                    'term_en'        => ['type' => 'STRING'],
                    'part_of_speech' => ['type' => 'STRING'],
                    'ipa'            => ['type' => 'STRING'],
                    'translation_tk' => ['type' => 'STRING'],
                    'translation_ru' => ['type' => 'STRING'],
                    'example_en'     => ['type' => 'STRING'],
                ],
                'required' => ['term_en', 'translation_tk', 'translation_ru'],
            ],
        ]],
        'required' => ['items'],
    ];

    $text = implode("\n", array_column($pool, 'text'));

    try {
        $vocab = $provider->complete(
            "You extract the key vocabulary a beginner must learn from a textbook section.\n"
            . "Give the Turkmen and Russian translation of each word.\n"
            . "Only words that actually appear in the text. 10-16 items.\n"
            . "NEVER include grammar terminology (singular, plural, verb, noun,\n"
            . "pronoun, grammar...) or textbook-apparatus words (exercise, chart,\n"
            . "complete, match) unless the section genuinely teaches them as\n"
            . "everyday words. Strict JSON.",
            "SECTION: {$label} — {$section->title}\n\nTEXT\n{$text}",
            $vocabSchema,
            0.1
        );

        $rows = [];
        foreach ($vocab->json['items'] ?? [] as $i => $item) {
            $rows[] = [
                'unit_section_id' => $section->id,
                'term_en'         => mb_substr((string) $item['term_en'], 0, 160),
                'part_of_speech'  => mb_substr((string) ($item['part_of_speech'] ?? ''), 0, 32) ?: null,
                'ipa'             => mb_substr((string) ($item['ipa'] ?? ''), 0, 120) ?: null,
                'translation_tk'  => mb_substr((string) $item['translation_tk'], 0, 255),
                'translation_ru'  => mb_substr((string) $item['translation_ru'], 0, 255),
                'example_en'      => mb_substr((string) ($item['example_en'] ?? ''), 0, 500) ?: null,
                'sort_order'      => $i,
                'created_at'      => $now,
                'updated_at'      => $now,
            ];
        }

        if ($rows !== []) {
            Capsule::table('vocabulary_items')->insert($rows);
        }

        echo '  ' . count($rows) . " vocabulary items\n";
    } catch (Throwable $e) {
        echo "  vocabulary failed: {$e->getMessage()}\n";
    }
}

$allowedVocabulary = Capsule::table('vocabulary_items')
    ->join('unit_sections', 'unit_sections.id', '=', 'vocabulary_items.unit_section_id')
    // FR-4.7: cumulative — this section and everything taught before it.
    ->where('unit_sections.level_position', '<=', $section->level_position)
    ->pluck('vocabulary_items.term_en')
    ->all();

echo 'Vocabulary ceiling: ' . count($allowedVocabulary) . " words\n";

// ---- 2. grammar ------------------------------------------------------

if (!Capsule::table('grammar_explanations')->where('unit_section_id', $section->id)->exists()) {
    echo "Writing grammar explanation (TM + RU) ...\n";

    $grammarSchema = [
        'type'       => 'OBJECT',
        'properties' => [
            'title_tk' => ['type' => 'STRING'],
            'title_ru' => ['type' => 'STRING'],
            'body_tk'  => ['type' => 'STRING'],
            'body_ru'  => ['type' => 'STRING'],
            'examples' => [
                'type'  => 'ARRAY',
                'items' => [
                    'type'       => 'OBJECT',
                    'properties' => [
                        'en'      => ['type' => 'STRING'],
                        'note_tk' => ['type' => 'STRING'],
                        'note_ru' => ['type' => 'STRING'],
                    ],
                    'required' => ['en', 'note_tk', 'note_ru'],
                ],
            ],
        ],
        'required' => ['title_tk', 'title_ru', 'body_tk', 'body_ru', 'examples'],
    ];

    try {
        $grammar = $provider->complete(
            "You explain English grammar to beginners in TURKMEN and RUSSIAN.\n"
            . "Simplify: short sentences, no grammar jargon the learner has not met.\n"
            . "Give at least 6 examples — more than the book does.\n"
            . "English examples stay in English; the notes are translated.\n"
            . "The Turkmen and Russian versions must say the same thing. Strict JSON.",
            "SECTION: {$label} — {$section->title}\n\nSOURCE TEXT\n"
            . implode("\n", array_column($pool, 'text')),
            $grammarSchema,
            0.2
        );

        $g = $grammar->json;
        Capsule::table('grammar_explanations')->insert([
            'unit_section_id' => $section->id,
            'title_tk'        => mb_substr((string) $g['title_tk'], 0, 200),
            'title_ru'        => mb_substr((string) $g['title_ru'], 0, 200),
            'body_tk'         => (string) $g['body_tk'],
            'body_ru'         => (string) $g['body_ru'],
            'examples'        => json_encode($g['examples'] ?? [], JSON_UNESCAPED_UNICODE),
            'status'          => $status,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        echo '  ok — ' . count($g['examples'] ?? []) . " examples\n";
    } catch (Throwable $e) {
        echo "  grammar failed: {$e->getMessage()}\n";
    }
}

// ---- 3. exercises ----------------------------------------------------

$instructions = [
    ExerciseSet::TYPE_MULTIPLE_CHOICE => ['Dogry jogaby saýlaň', 'Выберите правильный ответ'],
    ExerciseSet::TYPE_FILL_BLANK      => ['Boşluklary dolduryň', 'Заполните пропуски'],
    ExerciseSet::TYPE_REORDER         => ['Sözleri tertipleşdiriň', 'Расставьте слова по порядку'],
    ExerciseSet::TYPE_MATCH_PAIRS     => ['Jübütleri birleşdiriň', 'Соедините пары'],
];

$titles = [
    ExerciseSet::TYPE_MULTIPLE_CHOICE => ['Test', 'Тест'],
    ExerciseSet::TYPE_FILL_BLANK      => ['Boşluklary doldur', 'Заполни пропуски'],
    ExerciseSet::TYPE_REORDER         => ['Sözleri tertiple', 'Порядок слов'],
    ExerciseSet::TYPE_MATCH_PAIRS     => ['Jübütleri tap', 'Найди пары'],
];

$order = 0;

$only = $args['type'] ?? null;

foreach (array_keys($instructions) as $type) {
    if ($only !== null && $only !== $type) {
        continue;
    }

    // A section may hold several sets of the same type — a second batch
    // of multiple choice is more practice, not a conflict. Existing sets
    // are counted only so the new one gets a distinct name.
    $existing = Capsule::table('exercise_sets')
        ->where('unit_section_id', $section->id)
        ->where('type', $type)
        ->count();

    $suffix = $existing > 0 ? ' ' . ($existing + 1) : '';

    echo "Generating {$type}" . ($existing > 0 ? " (set #" . ($existing + 1) . ")" : '') . " ...\n";

    // Match pairs never touch the model: the pairs are the section's own
    // vocabulary list, term against translation (pair_mode
    // 'translation'). The generated version produced relation-free
    // guessing games like "tree - house".
    if ($type === ExerciseSet::TYPE_MATCH_PAIRS) {
        $pairs = $generator->buildPairsFromVocabulary($section, ExerciseSet::MAX_PAIRS);

        if (count($pairs) < ExerciseSet::MIN_PAIRS) {
            echo '  SKIPPED — only ' . count($pairs) . ' usable vocabulary pairs, needs '
                . ExerciseSet::MIN_PAIRS . "\n";
            continue;
        }

        $setId = Capsule::table('exercise_sets')->insertGetId([
            'unit_section_id' => $section->id,
            'type'            => $type,
            'title_tk'        => $titles[$type][0] . $suffix,
            'title_ru'        => $titles[$type][1] . $suffix,
            'instructions_tk' => $instructions[$type][0],
            'instructions_ru' => $instructions[$type][1],
            'input_mode'      => null,
            'pair_mode'       => 'translation',
            'status'          => $status,
            'sort_order'      => Capsule::table('exercise_sets')->where('unit_section_id', $section->id)->count(),
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        Capsule::table('questions')->insert([
            'exercise_set_id' => $setId,
            'sort_order'      => 0,
            'prompt_tk'       => $instructions[$type][0],
            'prompt_ru'       => $instructions[$type][1],
            'payload'         => json_encode(['pairs' => $pairs], JSON_UNESCAPED_UNICODE),
            'is_active'       => 1,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        echo '  1 question with ' . count($pairs) . " vocabulary pairs [{$status}]\n";
        continue;
    }

    try {
        $outcome = $generator->generateSet($section, $type, $pool, $allowedVocabulary);
    } catch (LlmRateLimitException $e) {
        echo "  RATE LIMITED — stopping. Re-run to continue.\n";
        break;
    } catch (Throwable $e) {
        echo "  failed: {$e->getMessage()}\n";
        continue;
    }

    $kept = count($outcome['kept']);
    $dropped = count($outcome['dropped']);

    // FR-4.20: never pad a type that the pages cannot support.
    if (!$outcome['viable']) {
        echo "  SKIPPED — only {$kept} valid items, needs {$outcome['min']} ({$dropped} dropped by gates)\n";
        foreach (array_slice($outcome['dropped'], 0, 6) as $d) {
            echo "    dropped: {$d['reason']}"
                . (isset($d['ratio']) ? " ratio={$d['ratio']}" : '')
                . (isset($d['detail']) ? " — {$d['detail']}" : '') . "\n";
        }
        continue;
    }

    if ($dropped > 0) {
        foreach ($outcome['dropped'] as $d) {
            echo "    dropped: {$d['reason']}"
                . (isset($d['detail']) ? " — {$d['detail']}" : '') . "\n";
        }
    }

    $setId = Capsule::table('exercise_sets')->insertGetId([
        'unit_section_id' => $section->id,
        'type'            => $type,
        'title_tk'        => $titles[$type][0] . $suffix,
        'title_ru'        => $titles[$type][1] . $suffix,
        'instructions_tk' => $instructions[$type][0],
        'instructions_ru' => $instructions[$type][1],
        'input_mode'      => $type === ExerciseSet::TYPE_FILL_BLANK ? 'word_bank' : null,
        'pair_mode'       => $type === ExerciseSet::TYPE_MATCH_PAIRS ? 'translation' : null,
        'status'          => $status,
        'sort_order'      => Capsule::table('exercise_sets')->where('unit_section_id', $section->id)->count(),
        'created_at'      => $now,
        'updated_at'      => $now,
    ]);

    $sort = 0;

    foreach (array_slice($outcome['kept'], 0, $outcome['max']) as $entry) {
        $item = $entry['item'];
        $source = $entry['source'];

        $payload = match ($type) {
            ExerciseSet::TYPE_MULTIPLE_CHOICE => [
                'stem'    => $item['stem'],
                'options' => $item['options'],
                'answer'  => (int) $item['answer_index'],
            ],
            ExerciseSet::TYPE_FILL_BLANK => [
                'before'    => $item['before'],
                'after'     => $item['after'],
                'answer'    => [$item['answer']],
                'word_bank' => $item['word_bank'],
            ],
            ExerciseSet::TYPE_REORDER => [
                'tokens' => $item['tokens'],
                'answer' => range(0, count($item['tokens']) - 1),
            ],
            ExerciseSet::TYPE_MATCH_PAIRS => [
                'pairs' => [['left' => $item['left'], 'right' => $item['right']]],
            ],
            default => [],
        };

        Capsule::table('questions')->insert([
            'exercise_set_id' => $setId,
            'sort_order'      => $sort++,
            'prompt_tk'       => $instructions[$type][0],
            'prompt_ru'       => $instructions[$type][1],
            'payload'         => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'source_book_id'  => $source['book'] ?? null,
            'source_page'     => $source['page'] ?? null,
            'source_sentence' => $source['text'] ?? null,
            'change_ratio'    => $entry['ratio'],
            'is_active'       => 1,
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);
    }

    echo "  {$sort} questions kept, {$dropped} dropped by gates [{$status}]\n";
}

echo "\nDone for section {$label}.\n";
$log->info('section generated', ['section_id' => $section->id, 'status' => $status]);
