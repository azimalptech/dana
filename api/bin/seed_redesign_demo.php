<?php

declare(strict_types=1);

/**
 * Minimal demo content for the redesigned structure — original items,
 * written here, not taken from any book.
 *
 *   php bin/seed_redesign_demo.php
 *
 * Seeds child unit 1A (unit_sections.id = 15):
 *  - two exercise sets under its existing Grammar section
 *    (fill_blank ×3, fill_letter_space ×2), three questions marked
 *    quiz-eligible,
 *  - a Quiz section, which owns no questions (FR-13.4).
 * Idempotent: bails if the quiz section already exists.
 */

require __DIR__ . '/../vendor/autoload.php';

use Dana\Database\Bootstrap;
use Dana\Support\Config;
use Illuminate\Database\Capsule\Manager as Capsule;

Bootstrap::boot(Config::load(dirname(__DIR__)));

$now = date('Y-m-d H:i:s');
$childUnit = 15; // 1A

if (Capsule::table('sections')->where('unit_section_id', $childUnit)->where('type', 'quiz')->exists()) {
    echo "Already seeded.\n";
    exit(0);
}

$grammarSection = Capsule::table('sections')
    ->where('unit_section_id', $childUnit)->where('type', 'grammar')->value('id');

if ($grammarSection === null) {
    fwrite(STDERR, "No grammar section on child unit {$childUnit}.\n");
    exit(1);
}

function makeSet(int $sectionId, string $type, string $titleTk, string $titleRu, string $now): int
{
    return (int) Capsule::table('exercise_sets')->insertGetId([
        'section_id'      => $sectionId,
        'type'            => $type,
        'title_tk'        => $titleTk,
        'title_ru'        => $titleRu,
        'instructions_tk' => $titleTk,
        'instructions_ru' => $titleRu,
        'input_mode'      => $type === 'fill_blank' ? 'word_bank' : null,
        'pair_mode'       => null,
        'status'          => 'published',
        'sort_order'      => (int) Capsule::table('exercise_sets')->where('section_id', $sectionId)->count(),
        'created_at'      => $now,
        'updated_at'      => $now,
    ]);
}

function addQ(int $setId, int $sort, array $payload, bool $eligible, string $now): void
{
    Capsule::table('questions')->insert([
        'exercise_set_id' => $setId,
        'question_type'   => 'text',
        'media_path'      => null,
        'quiz_eligible'   => $eligible ? 1 : 0,
        'sort_order'      => $sort,
        'prompt_tk'       => 'Boşluklary dolduryň',
        'prompt_ru'       => 'Заполните пропуски',
        'payload'         => json_encode($payload, JSON_UNESCAPED_UNICODE),
        'is_human_edited' => 1,
        'is_active'       => 1,
        'created_at'      => $now,
        'updated_at'      => $now,
    ]);
}

Capsule::connection()->transaction(function () use ($grammarSection, $childUnit, $now): void {
    $fb = makeSet($grammarSection, 'fill_blank', 'Boşluklary doldur', 'Заполни пропуски', $now);
    addQ($fb, 0, ['before' => 'My ', 'after' => ' is Alex.', 'answer' => ['name'],
        'word_bank' => ['name', 'house', 'tea', 'week']], true, $now);
    addQ($fb, 1, ['before' => 'Nice to ', 'after' => ' you.', 'answer' => ['meet'],
        'word_bank' => ['meet', 'name', 'please', 'goodbye']], true, $now);
    addQ($fb, 2, ['before' => 'See you next ', 'after' => '.', 'answer' => ['week'],
        'word_bank' => ['week', 'tea', 'name', 'meet']], false, $now);

    $fls = makeSet($grammarSection, 'fill_letter_space', 'Harplary doldur', 'Заполни буквы', $now);
    addQ($fls, 0, ['text' => 'My {name} is Alex.', 'reveal' => 2], true, $now);
    addQ($fls, 1, ['text' => 'Nice to {meet} you. See you next {week}.', 'reveal' => 1], false, $now);

    Capsule::table('sections')->insert([
        'unit_section_id' => $childUnit,
        'type'            => 'quiz',
        'title_tk'        => 'Synag',
        'title_ru'        => 'Экзамен',
        'status'          => 'published',
        'sort_order'      => 9,
        'created_at'      => $now,
        'updated_at'      => $now,
    ]);
});

echo "Seeded: fill_blank x3 + fill_letter_space x2 under grammar section {$grammarSection}, "
    . "3 quiz-eligible, quiz section created.\n";
