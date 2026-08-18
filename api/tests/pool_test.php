<?php

declare(strict_types=1);

/**
 * The sentence pool — stage 4 of docs/05-CONTENT-PIPELINE.md.
 *
 *   php tests/pool_test.php
 *
 * What reaches the model decides everything downstream, and the failure
 * mode is quiet: apparatus and speaker labels look like sentences, get
 * rewritten into exercises, and surface as "4 a ___ good." only when a
 * student is asked to answer it. These cases lock that behaviour down.
 *
 * Needs no database and no API key.
 */

require __DIR__ . '/../vendor/autoload.php';

use Dana\Content\Llm\GeminiProvider;
use Dana\Content\SectionGenerator;

$generator = new SectionGenerator(new GeminiProvider('unused', 'unused'));
$sentences = new ReflectionMethod(SectionGenerator::class, 'sentencesFrom');
$sentences->setAccessible(true);

$pass = 0;
$fail = 0;

/** @param list<string> $expected */
function check(string $label, string $page, array $expected): void
{
    global $generator, $sentences, $pass, $fail;

    $got = $sentences->invoke($generator, $page);
    $ok = $got === $expected;
    $ok ? $pass++ : $fail++;

    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . PHP_EOL;

    if (!$ok) {
        echo '        expected: ' . (implode(' | ', $expected) ?: '(nothing)') . PHP_EOL;
        echo '        got:      ' . (implode(' | ', $got) ?: '(nothing)') . PHP_EOL;
    }
}

echo "=== speaker labels and numbering are stripped ===\n";

check(
    'bare A/B dialogue speakers',
    "A Yes, she is from Spain.\nB Is she a good singer?",
    ['Yes, she is from Spain.', 'Is she a good singer?'],
);

check(
    'exercise numbering "4 a"',
    "4 a He is nice to me.\n5 b She is here with us.",
    ['He is nice to me.', 'She is here with us.'],
);

check('named speaker', 'Helen: Nice to meet you here.', ['Nice to meet you here.']);
check('numbered speaker', 'Barista 2: Are you from Turkey?', ['Are you from Turkey?']);

// Shipped defect: «b Where's she from?» reached exercises with the
// marker attached. A bare lowercase letter is a list mark only when a
// capital follows — the article in "a cappuccino" must survive.
check(
    'bare lowercase list marker "b" is stripped',
    "b Where's she from, do you know?",
    ["Where's she from, do you know?"],
);
check(
    'the article "a" before a lowercase noun survives',
    'a cappuccino, please, thank you.',
    ['a cappuccino, please, thank you.'],
);

echo "\n=== real words are NOT mistaken for labels ===\n";

// "A" is an English word. It may only be stripped on a page that is
// actually a dialogue, which is detected by a sibling "B" speaker.
check(
    '"A" survives outside a dialogue',
    'A cappuccino, please, thank you.',
    ['A cappuccino, please, thank you.'],
);

check('"I" is never a speaker mark', 'I am Tom, not Dom today.', ['I am Tom, not Dom today.']);

echo "\n=== textbook apparatus is excluded ===\n";

check('section heading', '1A A cappuccino, please to drink.', []);
check('rubric', '2 GRAMMAR verb be singular I and you.', []);
check('cross-reference', 'b p.92 Grammar Bank 1A for more.', []);
check('phonetic drill', '/aɪ/ bike I am nice five nine.', []);
check('instruction', 'Write I or You in photos one and two.', []);
check('fragment without terminal punctuation', '1 I am here with', []);
check('number drill', '1 2 3 4 5 6 7 8 9 10 one two.', []);

echo "\n=== excluded by FR-4.9 (speaking / listening) ===\n";

check('listening cue', 'Listen and tick the correct photo here.', []);
check('pairwork cue', 'In pairs, ask and answer these questions.', []);

echo "\n=== deduplication ===\n";

check(
    'the same sentence twice yields one',
    "She goes to school every day.\nShe goes to school every day.",
    ['She goes to school every day.'],
);

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
