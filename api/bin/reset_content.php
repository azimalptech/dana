<?php

declare(strict_types=1);

/**
 * Fresh-start reset (2026-08-14): wipe ALL content and ALL student
 * progress, keeping the structure the client set up — levels, units,
 * child units, classrooms and every user account. After this the course
 * skeleton is intact but empty; upload the xlsx files to fill it.
 *
 *   php bin/reset_content.php
 *
 * Deliberately NOT idempotent-guarded: it always empties the content
 * tables. It never touches users, levels, units, unit_sections,
 * classrooms, book_sets or the auth/audit tables.
 */

require __DIR__ . '/../vendor/autoload.php';

use Dana\Database\Bootstrap;
use Dana\Support\Config;
use Illuminate\Database\Capsule\Manager as Capsule;

Bootstrap::boot(Config::load(dirname(__DIR__)));

$before = [
    'questions'         => Capsule::table('questions')->count(),
    'vocabulary_items'  => Capsule::table('vocabulary_items')->count(),
    'exercise_sets'     => Capsule::table('exercise_sets')->count(),
    'sections'          => Capsule::table('sections')->count(),
    'section_attempts'  => Capsule::table('section_attempts')->count(),
    'quiz_draws'        => Capsule::table('quiz_draws')->count(),
];

// FK-safe order: children first. Each is a whole-table wipe.
$tables = [
    'attempt_answers',
    'section_attempts',
    'student_section_stats',
    'student_activity_days',
    'quiz_draws',
    'questions',
    'exercise_sets',
    'vocabulary_items',
    'grammar_explanations',
    'sections',
];

Capsule::connection()->transaction(function () use ($tables): void {
    foreach ($tables as $table) {
        Capsule::table($table)->delete();
    }
});

// Clear uploaded media (audio/image files for questions).
$mediaDir = dirname(__DIR__) . '/../storage/media';
if (is_dir($mediaDir)) {
    foreach (glob($mediaDir . '/*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

$after = [
    'questions'        => Capsule::table('questions')->count(),
    'vocabulary_items' => Capsule::table('vocabulary_items')->count(),
    'sections'         => Capsule::table('sections')->count(),
];
$kept = [
    'users'         => Capsule::table('users')->count(),
    'levels'        => Capsule::table('levels')->count(),
    'units'         => Capsule::table('units')->count(),
    'unit_sections' => Capsule::table('unit_sections')->count(),
    'classrooms'    => Capsule::table('classrooms')->count(),
];

echo "Wiped content + progress.\n";
foreach ($before as $t => $n) {
    echo sprintf("  %-18s %d -> %d\n", $t, $n, $after[$t] ?? 0);
}
echo "Kept (untouched): " . json_encode($kept) . "\n";
echo "The course skeleton is intact and empty — upload the xlsx files to fill it.\n";
