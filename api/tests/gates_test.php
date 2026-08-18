<?php

declare(strict_types=1);

/**
 * The single-answer gates — G10 and G11 of docs/05-CONTENT-PIPELINE.md.
 *
 *   php tests/gates_test.php
 *
 * Every case here is a real defect that shipped on 2026-08-06: banks
 * where several options were correct, the book's own word marked wrong,
 * grammar terminology inside items. These lock the gates that now stop
 * them.
 *
 * Needs no database and no API key.
 */

require __DIR__ . '/../vendor/autoload.php';

use Dana\Content\Llm\GeminiProvider;
use Dana\Content\SectionGenerator;
use Dana\Domain\Models\ExerciseSet;

$generator = new SectionGenerator(new GeminiProvider('unused', 'unused'));

$ambiguous = new ReflectionMethod(SectionGenerator::class, 'hasInterchangeableOptions');
$ambiguous->setAccessible(true);
$reproduces = new ReflectionMethod(SectionGenerator::class, 'distractorReproducesSource');
$reproduces->setAccessible(true);
$meta = new ReflectionMethod(SectionGenerator::class, 'usesMetalanguage');
$meta->setAccessible(true);

$pass = 0;
$fail = 0;

function check(string $label, bool $got, bool $expected): void
{
    global $pass, $fail;

    $ok = $got === $expected;
    $ok ? $pass++ : $fail++;

    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . PHP_EOL;
}

echo "=== G11: interchangeable options are rejected ===\n";

// Shipped defect #219: every option fits "See ___ on Friday."
check(
    'object pronouns all fit the gap',
    $ambiguous->invoke($generator, ExerciseSet::TYPE_FILL_BLANK, [
        'before' => 'See ', 'after' => ' on Friday.',
        'answer' => 'us', 'word_bank' => ['us', 'me', 'them', 'him'],
    ]),
    true,
);

// Shipped defect #221: any possessive works before "students".
check(
    'possessives all fit the gap',
    $ambiguous->invoke($generator, ExerciseSet::TYPE_FILL_BLANK, [
        'before' => 'Practise with ', 'after' => ' students.',
        'answer' => 'our', 'word_bank' => ['our', 'their', 'your', 'my'],
    ]),
    true,
);

// Shipped defect #256: "Is he from here?" and "from there?" are both fine.
check(
    'here/there both fit the gap',
    $ambiguous->invoke($generator, ExerciseSet::TYPE_FILL_BLANK, [
        'before' => 'Is he from ', 'after' => '?',
        'answer' => 'here', 'word_bank' => ['here', 'there', 'where', 'now'],
    ]),
    true,
);

// "See him on ___" with two days would have two right answers.
check(
    'two days of the week compete',
    $ambiguous->invoke($generator, ExerciseSet::TYPE_MULTIPLE_CHOICE, [
        'stem'    => 'See him on ___.',
        'options' => ['Friday', 'Monday', 'nice', 'meet'],
        'answer_index' => 0,
    ]),
    true,
);

echo "=== G11: agreement carve-out keeps honest grammar items ===\n";

// "are" pins the subject group: they is right, she/he/it are truly wrong.
check(
    '"Where are ___ from?" they vs she/he/it is a real item',
    $ambiguous->invoke($generator, ExerciseSet::TYPE_MULTIPLE_CHOICE, [
        'stem'    => 'Where are ___ from?',
        'options' => ['they', 'she', 'he', 'it'],
        'answer_index' => 0,
    ]),
    false,
);

// Shipped defect #258: she/he/it all agree with "is" — still ambiguous.
check(
    '"Is ___ from here?" she vs he/it stays rejected',
    $ambiguous->invoke($generator, ExerciseSet::TYPE_FILL_BLANK, [
        'before' => 'Is ', 'after' => ' from here?',
        'answer' => 'she', 'word_bank' => ['she', 'he', 'it', 'they'],
    ]),
    true,
);

check(
    'options from different classes pass',
    $ambiguous->invoke($generator, ExerciseSet::TYPE_MULTIPLE_CHOICE, [
        'stem'    => 'Nice to ___ her.',
        'options' => ['meet', 'please', 'tea', 'week'],
        'answer_index' => 0,
    ]),
    false,
);

echo "=== G10: the book's word is never a wrong option ===\n";

// Shipped defect #220: answer "names" while the source said "students".
check(
    'distractor that rebuilds the source sentence is rejected',
    $reproduces->invoke($generator, ExerciseSet::TYPE_FILL_BLANK, [
        'before' => 'Practise with other ', 'after' => '.',
        'answer' => 'names', 'word_bank' => ['names', 'students', 'weeks', 'bikes'],
    ], 'Practise with other students.'),
    true,
);

check(
    'distractors that do not rebuild the source pass',
    $reproduces->invoke($generator, ExerciseSet::TYPE_FILL_BLANK, [
        'before' => 'Practise with other ', 'after' => '.',
        'answer' => 'students', 'word_bank' => ['students', 'weeks', 'trees', 'bikes'],
    ], 'Practise with other students.'),
    false,
);

check(
    'multiple choice: source-rebuilding option is rejected',
    $reproduces->invoke($generator, ExerciseSet::TYPE_MULTIPLE_CHOICE, [
        'stem'    => 'See you on ___.',
        'options' => ['Monday', 'Friday', 'nice', 'meet'],
        'answer_index' => 0,
    ], 'See you on Friday.'),
    true,
);

echo "=== metalanguage never appears inside an item ===\n";

// Shipped defect #245: "singular" offered as a distractor.
check(
    'grammar terminology in options is rejected',
    $meta->invoke($generator, ExerciseSet::TYPE_MULTIPLE_CHOICE, [
        'stem'    => "It's a ___ house.",
        'options' => ['nice', 'name', 'meet', 'singular'],
        'answer_index' => 0,
    ]),
    true,
);

check(
    'ordinary vocabulary passes',
    $meta->invoke($generator, ExerciseSet::TYPE_FILL_BLANK, [
        'before' => 'Nice to ', 'after' => ' her.',
        'answer' => 'meet', 'word_bank' => ['meet', 'please', 'tea', 'week'],
    ]),
    false,
);

echo PHP_EOL . "{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
