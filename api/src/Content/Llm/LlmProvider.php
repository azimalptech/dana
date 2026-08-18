<?php

declare(strict_types=1);

namespace Dana\Content\Llm;

/**
 * FR-4.18: generation runs behind this interface so switching provider
 * is a config change, never a code change.
 *
 * Implementations must:
 *  - return parsed JSON when a schema is supplied, or throw
 *  - surface rate limiting as LlmRateLimitException so the worker can
 *    back off instead of failing the run
 *  - never log the API key or the full prompt body
 */
interface LlmProvider
{
    /** 'claude' | 'gemini' | 'deepseek' — matches generation_runs.provider */
    public function name(): string;

    public function model(): string;

    /**
     * @param array<string, mixed>|null $jsonSchema When given, the
     *        response is constrained to this shape and parsed.
     */
    public function complete(
        string $systemPrompt,
        string $userPrompt,
        ?array $jsonSchema = null,
        float $temperature = 0.2,
    ): LlmResult;
}
