<?php

declare(strict_types=1);

namespace Dana\Content\Llm;

use Dana\Support\Config;

/**
 * FR-4.18: which provider runs is configuration, not code.
 *
 * Two roles, so the expensive model isn't used for cheap work:
 *   generate — writes the exercises and grammar explanations
 *   judge    — the G8 validation pass, run on every question
 */
final class ProviderFactory
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    public function forGeneration(): LlmProvider
    {
        return $this->build('generate');
    }

    public function forJudging(): LlmProvider
    {
        return $this->build('judge');
    }

    /**
     * Checked before a run is queued, so an unconfigured provider fails
     * immediately with a clear message rather than part-way through.
     */
    public function assertConfigured(): void
    {
        if (!$this->config->llmConfigured()) {
            throw new LlmException(
                'No LLM provider key configured. Set ANTHROPIC_API_KEY, GEMINI_API_KEY or '
                . 'DEEPSEEK_API_KEY in api/.env. Note a Claude.ai subscription is not usable '
                . 'here — the server needs an API key from console.anthropic.com.'
            );
        }
    }

    private function build(string $role): LlmProvider
    {
        $provider = strtolower((string) $this->config->get('LLM_PROVIDER', 'claude'));

        return match ($provider) {
            'gemini' => new GeminiProvider(
                apiKey: $this->requireKey('GEMINI_API_KEY', 'gemini'),
                model: $this->config->get(
                    $role === 'judge' ? 'GEMINI_MODEL_JUDGE' : 'GEMINI_MODEL_GENERATE',
                    $role === 'judge' ? 'gemini-3.5-flash-lite' : 'gemini-3.5-flash'
                ) ?? 'gemini-3.5-flash',
            ),
            'claude', 'deepseek' => throw new LlmException(
                "Provider '{$provider}' is not wired up yet. Only 'gemini' is available; "
                . 'set LLM_PROVIDER=gemini or supply an Anthropic key.'
            ),
            default => throw new LlmException("Unknown LLM_PROVIDER '{$provider}'."),
        };
    }

    private function requireKey(string $name, string $provider): string
    {
        $key = $this->config->get($name);

        if ($key === null) {
            throw new LlmException("LLM_PROVIDER is '{$provider}' but {$name} is empty in api/.env.");
        }

        return $key;
    }
}
