<?php

declare(strict_types=1);

namespace Dana\Support;

use Dotenv\Dotenv;
use RuntimeException;

/**
 * Environment-backed configuration.
 *
 * NFR-4: nothing in the codebase may assume localhost. Every
 * environment-specific value is read through here, so moving from the
 * XAMPP machine to a real server is configuration, not a rewrite.
 */
final class Config
{
    private static ?self $instance = null;

    /** @var array<string, string> */
    private array $values;

    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function load(string $basePath): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        if (is_file($basePath . '/.env')) {
            Dotenv::createImmutable($basePath)->load();
        }

        return self::$instance = new self($_ENV);
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new RuntimeException('Config::load() must be called first.');
        }

        return self::$instance;
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->values[$key] ?? $default;

        return $value === '' ? $default : $value;
    }

    /**
     * For values the application cannot sensibly run without. Fails at
     * boot with a clear message rather than at the first request.
     */
    public function require(string $key): string
    {
        $value = $this->get($key);

        if ($value === null) {
            throw new RuntimeException(
                "Missing required config '{$key}'. Copy api/.env.example to api/.env and fill it in."
            );
        }

        return $value;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key);

        return $value === null ? $default : (int) $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * FR-4.18: generation is disabled until a provider key is supplied.
     * A Claude.ai subscription is not usable here — this needs an API
     * key from console.anthropic.com.
     */
    public function llmConfigured(): bool
    {
        return $this->get('ANTHROPIC_API_KEY') !== null
            || $this->get('GEMINI_API_KEY') !== null
            || $this->get('DEEPSEEK_API_KEY') !== null;
    }
}
