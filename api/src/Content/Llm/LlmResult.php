<?php

declare(strict_types=1);

namespace Dana\Content\Llm;

/**
 * One model response. Token counts are recorded on every generation_run
 * so cost per unit section is measurable rather than guessed.
 */
final class LlmResult
{
    public function __construct(
        public readonly string $text,
        public readonly ?array $json,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly string $provider,
        public readonly string $model,
    ) {
    }
}
