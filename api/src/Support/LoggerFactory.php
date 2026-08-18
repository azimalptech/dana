<?php

declare(strict_types=1);

namespace Dana\Support;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use RuntimeException;

/**
 * File logging, one rotating file per channel.
 *
 *   storage/logs/app-YYYY-MM-DD.log     unexpected failures
 *   storage/logs/auth-YYYY-MM-DD.log    logins, refreshes, logouts
 *   storage/logs/worker-YYYY-MM-DD.log  content generation runs
 *
 * NEVER log a password, an access token, a refresh token, or the
 * contents of APP_CRED_KEY. Log identifiers and outcomes — enough to
 * investigate an incident, not enough to become one.
 *
 * Security-relevant *actions* (password reveals, unlocks, course
 * closures) go to the `audit_log` table instead — those must be
 * queryable and must survive log rotation (FR-1.12).
 */
final class LoggerFactory
{
    /** @var array<string, Logger> */
    private static array $channels = [];

    public static function get(Config $config, string $channel = 'app'): Logger
    {
        if (isset(self::$channels[$channel])) {
            return self::$channels[$channel];
        }

        $logger = new Logger($channel);
        $directory = self::directory($config);
        $level = self::level($config);

        $formatter = new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context%\n",
            'Y-m-d H:i:s',
            true,   // allow newlines in messages
            true    // ignore empty context
        );

        $handler = new RotatingFileHandler(
            $directory . '/' . $channel . '.log',
            $config->int('LOG_MAX_FILES', 30),
            $level
        );
        $handler->setFormatter($formatter);
        $logger->pushHandler($handler);

        // Under `php -S` this surfaces problems in the terminal too.
        if ($config->bool('APP_DEBUG')) {
            $stderr = new StreamHandler('php://stderr', Level::Warning);
            $stderr->setFormatter($formatter);
            $logger->pushHandler($stderr);
        }

        return self::$channels[$channel] = $logger;
    }

    private static function directory(Config $config): string
    {
        $base = $config->get('LOG_PATH') ?? (($config->get('STORAGE_PATH', '../storage')) . '/logs');

        if (!str_starts_with($base, '/') && !preg_match('/^[A-Za-z]:/', $base)) {
            $base = dirname(__DIR__, 2) . '/' . $base;
        }

        if (!is_dir($base) && !mkdir($base, 0775, true) && !is_dir($base)) {
            throw new RuntimeException("Cannot create log directory: {$base}");
        }

        return rtrim($base, '/\\');
    }

    private static function level(Config $config): Level
    {
        return match (strtolower((string) $config->get('LOG_LEVEL', 'info'))) {
            'debug'   => Level::Debug,
            'notice'  => Level::Notice,
            'warning' => Level::Warning,
            'error'   => Level::Error,
            default   => Level::Info,
        };
    }
}
