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
            // MUTABLE, i.e. api/.env wins over anything already in the
            // machine's environment. Immutable was the opposite: dotenv
            // silently skipped every key the OS had already defined, so
            // the file the whole deploy guide tells you to edit could be
            // ignored with no error anywhere.
            //
            // That is not hypothetical (2026-09-13): a stale
            // GEMINI_API_KEY left in the developer's Windows environment
            // shadowed the new one in api/.env, and the key read back as
            // absent — Config saw neither value, because dotenv had
            // skipped the assignment and $_ENV never received it. The
            // same trap sits under DB_PASSWORD and JWT_SECRET on any
            // host where someone once exported one.
            //
            // Dana configures itself from api/.env and nothing else
            // (NFR-4, and DEPLOY.md §5), so the file is the authority.
            Dotenv::createMutable($basePath)->load();
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
