<?php

declare(strict_types=1);

/**
 * OCRs and generates every section of a unit, in one go.
 *
 *   php bin/fill_unit.php --unit=2 --publish
 *
 * Safe to re-run. Pages already transcribed are skipped, exercise types
 * already present are left alone, and a free-tier 429 stops the run
 * cleanly rather than corrupting anything — just run it again when quota
 * resets and it picks up where it stopped.
 */

require __DIR__ . '/../vendor/autoload.php';

use Dana\Database\Bootstrap;
use Dana\Domain\Models\Level;
use Dana\Support\Config;
use Illuminate\Database\Capsule\Manager as Capsule;

$config = Config::load(dirname(__DIR__));
Bootstrap::boot($config);

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/s', $arg, $m) === 1) {
        $args[$m[1]] = $m[2] ?? '1';
    }
}

$unitNumber = (int) ($args['unit'] ?? 0);
$publish = isset($args['publish']) ? ' --publish' : '';

if ($unitNumber === 0) {
    fwrite(STDERR, "Usage: php bin/fill_unit.php --unit=2 [--publish]\n");
    exit(1);
}

$level = Level::query()->where('slug', $args['level'] ?? 'beginner')->firstOrFail();

$sections = Capsule::table('unit_sections')
    ->join('units', 'units.id', '=', 'unit_sections.unit_id')
    ->where('units.level_id', $level->id)
    ->where('units.number', $unitNumber)
    ->orderBy('unit_sections.sort_order')
    ->select('unit_sections.id', 'unit_sections.code', 'unit_sections.title')
    ->get();

if ($sections->isEmpty()) {
    fwrite(STDERR, "No sections for unit {$unitNumber}. Run bin/seed_curriculum.php first.\n");
    exit(1);
}

$php = PHP_BINARY;
$dir = __DIR__;

foreach ($sections as $section) {
    echo "\n=== Unit {$unitNumber}{$section->code} — {$section->title} ===\n";

    $sources = Capsule::table('section_sources')->where('unit_section_id', $section->id)->get();

    foreach ($sources as $source) {
        $missing = Capsule::table('book_pages')
            ->where('book_id', $source->book_id)
            ->whereBetween('page_number', [$source->page_from, $source->page_to])
            ->whereRaw('CHAR_LENGTH(COALESCE(raw_text, "")) <= 20')
            ->count();

        $absent = ($source->page_to - $source->page_from + 1) - Capsule::table('book_pages')
            ->where('book_id', $source->book_id)
            ->whereBetween('page_number', [$source->page_from, $source->page_to])
            ->count();

        if ($missing > 0 || $absent > 0) {
            echo "-- OCR pages {$source->page_from}-{$source->page_to}\n";
            passthru(sprintf(
                '%s %s --book=%d --from=%d --to=%d',
                escapeshellarg($php),
                escapeshellarg($dir . '/ocr_pages.php'),
                $source->book_id,
                $source->page_from,
                $source->page_to
            ), $code);

            if ($code !== 0) {
                fwrite(STDERR, "OCR stopped. Re-run this command when quota allows.\n");
                exit($code);
            }
        }
    }

    echo "-- generate\n";
    passthru(sprintf(
        '%s %s --section=%d%s',
        escapeshellarg($php),
        escapeshellarg($dir . '/generate_section.php'),
        $section->id,
        $publish
    ), $code);

    if ($code !== 0) {
        fwrite(STDERR, "Generation stopped for {$unitNumber}{$section->code}. Re-run when quota allows.\n");
        exit($code);
    }
}

echo "\nUnit {$unitNumber} complete.\n";
