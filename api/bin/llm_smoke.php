<?php

declare(strict_types=1);

/**
 * A miniature version of the real generation run: rewrite three source
 * sentences into multiple-choice questions, then measure whether each
 * landed inside the 20–25% change band (FR-4.4).
 *
 * Costs ONE request. Use it to check a provider key works end to end
 * before queueing a real unit section.
 *
 *   php bin/llm_smoke.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Dana\Content\Llm\LlmException;
use Dana\Content\Llm\LlmRateLimitException;
use Dana\Content\Llm\ProviderFactory;
use Dana\Content\Validation\ChangeRatio;
use Dana\Support\Config;

$config = Config::load(dirname(__DIR__));
$factory = new ProviderFactory($config);

try {
    $factory->assertConfigured();
    $provider = $factory->forGeneration();
} catch (LlmException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo "provider: {$provider->name()} / {$provider->model()}\n\n";

// Stands in for the sentence pool built from the mapped book pages.
$sources = [
    'She goes to school every day by bus.',
    'My brother works in a small office near the station.',
    'They do not like cold weather in the winter.',
];

$vocabulary = 'school, bus, brother, office, station, weather, winter, work, go, like, cold, small, near, day';

$system = <<<'PROMPT'
You rewrite existing textbook sentences into exercises.
You never write new sentences from scratch.

For each question you MUST:
  - pick exactly one SOURCE SENTENCE and return its index
  - change 20-25% of its words - no less, no more
  - keep the grammatical structure, register and logic intact
  - use only words from ALLOWED VOCABULARY plus function words
  - use only present simple tense
  - produce exactly 1 correct option and 3 incorrect but plausible ones

Return strict JSON only.
PROMPT;

$user = "SOURCE SENTENCES\n";
foreach ($sources as $i => $sentence) {
    $user .= "{$i}. {$sentence}\n";
}
$user .= "\nALLOWED VOCABULARY\n{$vocabulary}\n\nTASK\nProduce 3 multiple-choice questions.";

$schema = [
    'type' => 'OBJECT',
    'properties' => [
        'questions' => [
            'type'  => 'ARRAY',
            'items' => [
                'type'       => 'OBJECT',
                'properties' => [
                    'source_index' => ['type' => 'INTEGER'],
                    'stem'         => ['type' => 'STRING'],
                    'options'      => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                    'answer_index' => ['type' => 'INTEGER'],
                ],
                'required' => ['source_index', 'stem', 'options', 'answer_index'],
            ],
        ],
    ],
    'required' => ['questions'],
];

try {
    $result = $provider->complete($system, $user, $schema);
} catch (LlmRateLimitException $e) {
    fwrite(STDERR, "RATE LIMITED: {$e->getMessage()}\nThis is expected on a free-tier key. Retry later.\n");
    exit(2);
} catch (LlmException $e) {
    fwrite(STDERR, "FAILED: {$e->getMessage()}\n");
    exit(1);
}

echo "tokens: {$result->inputTokens} in / {$result->outputTokens} out\n\n";

$onTarget = 0;
$accepted = 0;
$total = 0;

foreach ($result->json['questions'] ?? [] as $q) {
    $total++;
    $index = (int) ($q['source_index'] ?? -1);
    $source = $sources[$index] ?? null;

    if ($source === null) {
        echo "  [G1 FAIL] question has no valid source index — would be dropped\n";
        continue;
    }

    // The stem carries the blank; compare the answered sentence against
    // its source, which is what the real gate does.
    $filled = str_replace('___', (string) ($q['options'][$q['answer_index']] ?? ''), (string) $q['stem']);
    $ratio = ChangeRatio::measure($source, $filled);

    $verdict = ChangeRatio::isOnTarget($ratio)
        ? 'ON TARGET'
        : (ChangeRatio::isAccepted($ratio) ? 'accepted' : 'REJECTED');

    if (ChangeRatio::isOnTarget($ratio)) {
        $onTarget++;
    }
    if (ChangeRatio::isAccepted($ratio)) {
        $accepted++;
    }

    echo "  src: {$source}\n";
    echo "  out: {$filled}\n";
    echo "  change_ratio: " . number_format($ratio, 3) . "  [{$verdict}]\n\n";
}

echo "{$total} generated, {$accepted} within the accepted band, {$onTarget} on target.\n";
exit($total > 0 ? 0 : 1);
