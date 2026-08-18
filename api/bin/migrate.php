<?php

declare(strict_types=1);

/**
 * Applies .sql files from database/migrations in filename order, once
 * each. Tracks what has run in a `migrations` table.
 *
 *   php bin/migrate.php            apply pending migrations
 *   php bin/migrate.php --status   show what would run
 */

require __DIR__ . '/../vendor/autoload.php';

use Dana\Database\Bootstrap;
use Dana\Support\Config;
use Illuminate\Database\Capsule\Manager as Capsule;

$basePath = dirname(__DIR__);
$config = Config::load($basePath);
Bootstrap::boot($config);

$statusOnly = in_array('--status', $argv, true);

Capsule::connection()->statement(
    'CREATE TABLE IF NOT EXISTS migrations (
        id         BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
        filename   VARCHAR(255) NOT NULL,
        applied_at DATETIME NOT NULL,
        UNIQUE KEY uq_migration (filename)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

$applied = Capsule::table('migrations')->pluck('filename')->all();
$files = glob($basePath . '/database/migrations/*.sql') ?: [];
sort($files);

$pending = array_values(array_filter(
    $files,
    static fn (string $f): bool => !in_array(basename($f), $applied, true)
));

if ($pending === []) {
    echo "Nothing to migrate. " . count($applied) . " already applied.\n";
    exit(0);
}

foreach ($pending as $file) {
    $name = basename($file);

    if ($statusOnly) {
        echo "PENDING  {$name}\n";
        continue;
    }

    echo "Applying {$name} ... ";

    $statements = splitSqlStatements((string) file_get_contents($file));
    $connection = Capsule::connection();

    try {
        foreach ($statements as $statement) {
            $connection->unprepared($statement);
        }
    } catch (Throwable $e) {
        echo "FAILED\n\n" . $e->getMessage() . "\n";
        exit(1);
    }

    Capsule::table('migrations')->insert([
        'filename'   => $name,
        'applied_at' => date('Y-m-d H:i:s'),
    ]);

    echo "ok (" . count($statements) . " statements)\n";
}

echo "Done.\n";

/**
 * Splits a migration file into statements.
 *
 * DDL only — no stored procedures, triggers or DELIMITER blocks — so
 * stripping comments and splitting on semicolons is sufficient. String
 * literals are respected so a semicolon inside quotes cannot split a
 * statement.
 *
 * @return list<string>
 */
function splitSqlStatements(string $sql): array
{
    $statements = [];
    $current = '';
    $length = strlen($sql);
    $inSingle = false;
    $inDouble = false;
    $inComment = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        if ($inComment) {
            if ($char === "\n") {
                $inComment = false;
                $current .= $char;
            }
            continue;
        }

        if (!$inSingle && !$inDouble && $char === '-' && $next === '-') {
            $inComment = true;
            $i++;
            continue;
        }

        if ($char === "'" && !$inDouble && ($sql[$i - 1] ?? '') !== '\\') {
            $inSingle = !$inSingle;
        } elseif ($char === '"' && !$inSingle && ($sql[$i - 1] ?? '') !== '\\') {
            $inDouble = !$inDouble;
        }

        if ($char === ';' && !$inSingle && !$inDouble) {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
            continue;
        }

        $current .= $char;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}
