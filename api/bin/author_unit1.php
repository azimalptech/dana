<?php

declare(strict_types=1);

/**
 * Original, hand-authored exercises for Unit 1 (sections 1A and 1B).
 *
 *   php bin/author_unit1.php
 *
 * These items are written from scratch against each section's grammar
 * scope and vocabulary list — they are NOT taken from any book. Every
 * item is passed through the same deterministic gates that guard
 * generated content (interchangeable-option and metalanguage checks in
 * SectionGenerator) before it may touch the database; one failure aborts
 * the whole run.
 *
 * Idempotent: refuses to run if a set with the same title already
 * exists for the section.
 */

require __DIR__ . '/../vendor/autoload.php';

use Dana\Content\Llm\GeminiProvider;
use Dana\Content\SectionGenerator;
use Dana\Database\Bootstrap;
use Dana\Domain\Models\ExerciseSet;
use Dana\Support\Config;
use Illuminate\Database\Capsule\Manager as Capsule;

$config = Config::load(dirname(__DIR__));
Bootstrap::boot($config);

// The provider is never called — the generator instance only lends its
// validation helpers.
$generator = new SectionGenerator(new GeminiProvider('unused', 'unused'));
$ambiguous = new ReflectionMethod(SectionGenerator::class, 'hasInterchangeableOptions');
$ambiguous->setAccessible(true);
$meta = new ReflectionMethod(SectionGenerator::class, 'usesMetalanguage');
$meta->setAccessible(true);
$shape = new ReflectionMethod(SectionGenerator::class, 'hasValidShape');
$shape->setAccessible(true);

/**
 * @param array{type: string, title_tk: string, title_ru: string, items: list<array>} $set
 */
function validate(array $set, ReflectionMethod $shape, ReflectionMethod $ambiguous, ReflectionMethod $meta, SectionGenerator $generator): void
{
    foreach ($set['items'] as $i => $item) {
        $label = "{$set['title_ru']} #" . ($i + 1);

        if (!$shape->invoke($generator, $set['type'], $item)) {
            fwrite(STDERR, "SHAPE FAIL: {$label}\n");
            exit(1);
        }

        if ($ambiguous->invoke($generator, $set['type'], $item)) {
            fwrite(STDERR, "AMBIGUOUS OPTIONS: {$label}\n");
            exit(1);
        }

        if ($meta->invoke($generator, $set['type'], $item)) {
            fwrite(STDERR, "METALANGUAGE: {$label}\n");
            exit(1);
        }
    }
}

// ---------------------------------------------------------------- 1A --
// Grammar scope: verb "be" with I/you, greetings and courtesy phrases.
// Vocabulary: please, name, nice, meet, minute, house, bike, tree, tea,
// week, goodbye, English, Friday.

$sets = [
    15 => [
        [
            'type'     => ExerciseSet::TYPE_FILL_BLANK,
            'title_tk' => 'Boşluklary doldur',
            'title_ru' => 'Заполни пропуски',
            'items'    => [
                ['before' => 'My ', 'after' => ' is Alex.', 'answer' => 'name', 'word_bank' => ['name', 'house', 'tea', 'week']],
                ['before' => 'A cup of ', 'after' => ', please.', 'answer' => 'tea', 'word_bank' => ['tea', 'name', 'week', 'goodbye']],
                ['before' => 'Nice to ', 'after' => ' you.', 'answer' => 'meet', 'word_bank' => ['meet', 'name', 'please', 'goodbye']],
                ['before' => '', 'after' => ' classes are on Friday.', 'answer' => 'English', 'word_bank' => ['English', 'goodbye', 'minute', 'meet']],
                ['before' => 'Wait a ', 'after' => ', please.', 'answer' => 'minute', 'word_bank' => ['minute', 'goodbye', 'tree', 'name']],
                ['before' => 'See you next ', 'after' => '.', 'answer' => 'week', 'word_bank' => ['week', 'tea', 'name', 'meet']],
                ['before' => '', 'after' => '! See you on Friday.', 'answer' => 'Goodbye', 'word_bank' => ['Goodbye', 'Minute', 'Tree', 'Meet']],
            ],
        ],
        [
            'type'     => ExerciseSet::TYPE_REORDER,
            'title_tk' => 'Sözleri tertiple',
            'title_ru' => 'Порядок слов',
            'items'    => [
                ['tokens' => ['What', 'is', 'your', 'name', '?']],
                ['tokens' => ['Nice', 'to', 'meet', 'you', '.']],
                ['tokens' => ['See', 'you', 'on', 'Friday', '.']],
                ['tokens' => ['A', 'cup', 'of', 'tea', ',', 'please', '.']],
                ['tokens' => ['My', 'English', 'class', 'is', 'on', 'Friday', '.']],
                ['tokens' => ['I', 'am', 'from', 'Turkmenistan', '.']],
                ['tokens' => ['This', 'is', 'my', 'house', '.']],
            ],
        ],
    ],

    // ------------------------------------------------------------ 1B --
    // Grammar scope: "be" with he/she/it, Where...from? questions.
    // Vocabulary (cumulative): world, music, country, city, conversation,
    // good, photo, chart, where — plus everything from 1A.
    16 => [
        [
            'type'     => ExerciseSet::TYPE_MULTIPLE_CHOICE,
            'title_tk' => 'Test',
            'title_ru' => 'Тест',
            'items'    => [
                ['stem' => '___ is she from?', 'options' => ['Where', 'Good', 'World', 'City'], 'answer_index' => 0],
                ['stem' => 'He is from a big ___.', 'options' => ['city', 'music', 'where', 'good'], 'answer_index' => 0],
                ['stem' => 'She is ___ at English.', 'options' => ['good', 'city', 'photo', 'where'], 'answer_index' => 0],
                ['stem' => 'This is a ___ of my city.', 'options' => ['photo', 'music', 'tea', 'week'], 'answer_index' => 0],
                ['stem' => 'Where ___ he from?', 'options' => ['is', 'are', 'am', 'where'], 'answer_index' => 0],
                ['stem' => 'I like pop ___.', 'options' => ['music', 'city', 'photo', 'conversation'], 'answer_index' => 0],
                ['stem' => 'It is a good ___ about music.', 'options' => ['conversation', 'city', 'world', 'minute'], 'answer_index' => 0],
                ['stem' => 'She is from a small ___.', 'options' => ['country', 'music', 'photo', 'where'], 'answer_index' => 0],
            ],
        ],
        [
            'type'     => ExerciseSet::TYPE_FILL_BLANK,
            'title_tk' => 'Boşluklary doldur',
            'title_ru' => 'Заполни пропуски',
            'items'    => [
                ['before' => 'Where are you ', 'after' => '?', 'answer' => 'from', 'word_bank' => ['from', 'good', 'where', 'music']],
                ['before' => 'The ', 'after' => ' is big.', 'answer' => 'world', 'word_bank' => ['world', 'music', 'where', 'good']],
                ['before' => '', 'after' => ' is he from?', 'answer' => 'Where', 'word_bank' => ['Where', 'Good', 'Photo', 'Music']],
                ['before' => 'This ', 'after' => ' is good.', 'answer' => 'music', 'word_bank' => ['music', 'where', 'from', 'goodbye']],
                ['before' => 'Is she from your ', 'after' => '?', 'answer' => 'city', 'word_bank' => ['city', 'good', 'where', 'from']],
                ['before' => 'It is a photo of my ', 'after' => '.', 'answer' => 'city', 'word_bank' => ['city', 'good', 'where', 'music']],
                ['before' => 'They are from another ', 'after' => '.', 'answer' => 'country', 'word_bank' => ['country', 'music', 'photo', 'good']],
            ],
        ],
        [
            'type'     => ExerciseSet::TYPE_REORDER,
            'title_tk' => 'Sözleri tertiple',
            'title_ru' => 'Порядок слов',
            'items'    => [
                ['tokens' => ['Where', 'are', 'you', 'from', '?']],
                ['tokens' => ['She', 'is', 'from', 'a', 'big', 'city', '.']],
                ['tokens' => ['The', 'music', 'is', 'good', '.']],
                ['tokens' => ['This', 'is', 'a', 'photo', 'of', 'my', 'city', '.']],
                ['tokens' => ['The', 'world', 'is', 'big', '.']],
                ['tokens' => ['It', 'is', 'a', 'good', 'conversation', '.']],
                ['tokens' => ['I', 'am', 'from', 'a', 'small', 'country', '.']],
            ],
        ],
    ],
];

$instructions = [
    ExerciseSet::TYPE_MULTIPLE_CHOICE => ['Dogry jogaby saýlaň', 'Выберите правильный ответ'],
    ExerciseSet::TYPE_FILL_BLANK      => ['Boşluklary dolduryň', 'Заполните пропуски'],
    ExerciseSet::TYPE_REORDER         => ['Sözleri tertipleşdiriň', 'Расставьте слова по порядку'],
];

$now = date('Y-m-d H:i:s');

foreach ($sets as $sectionId => $sectionSets) {
    foreach ($sectionSets as $set) {
        validate($set, $shape, $ambiguous, $meta, $generator);

        $exists = Capsule::table('exercise_sets')
            ->where('unit_section_id', $sectionId)
            ->where('type', $set['type'])
            ->where('title_ru', $set['title_ru'])
            ->exists();

        if ($exists) {
            echo "SKIP (already there): section {$sectionId} {$set['title_ru']}\n";
            continue;
        }

        $setId = Capsule::table('exercise_sets')->insertGetId([
            'unit_section_id' => $sectionId,
            'type'            => $set['type'],
            'title_tk'        => $set['title_tk'],
            'title_ru'        => $set['title_ru'],
            'instructions_tk' => $instructions[$set['type']][0],
            'instructions_ru' => $instructions[$set['type']][1],
            'input_mode'      => $set['type'] === ExerciseSet::TYPE_FILL_BLANK ? 'word_bank' : null,
            'pair_mode'       => null,
            'status'          => 'published',
            'sort_order'      => Capsule::table('exercise_sets')->where('unit_section_id', $sectionId)->count(),
            'created_at'      => $now,
            'updated_at'      => $now,
        ]);

        $sort = 0;

        foreach ($set['items'] as $item) {
            $payload = match ($set['type']) {
                ExerciseSet::TYPE_MULTIPLE_CHOICE => [
                    'stem'    => $item['stem'],
                    'options' => $item['options'],
                    'answer'  => $item['answer_index'],
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
            };

            Capsule::table('questions')->insert([
                'exercise_set_id' => $setId,
                'sort_order'      => $sort++,
                'prompt_tk'       => $instructions[$set['type']][0],
                'prompt_ru'       => $instructions[$set['type']][1],
                'payload'         => json_encode($payload, JSON_UNESCAPED_UNICODE),
                // Original authorship — no book provenance, marked human.
                'source_book_id'  => null,
                'source_page'     => null,
                'source_sentence' => null,
                'change_ratio'    => null,
                'is_human_edited' => 1,
                'is_active'       => 1,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }

        echo "OK: section {$sectionId} {$set['title_ru']} — {$sort} questions [published]\n";
    }
}

echo "Done.\n";
