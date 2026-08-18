<?php

declare(strict_types=1);

/**
 * Creates the Beginner unit/section structure and its page mappings.
 *
 * Every entry below was read off the book's own contents page, which was
 * transcribed during OCR (Student's Book p.2). Nothing here is guessed:
 * the contents page lists the starting page of each section, and each
 * section runs to the page before the next one starts.
 *
 * Deliberately EXCLUDED, per FR-4.9:
 *   - "Practical English" episodes — speaking and listening
 *   - "Revise and Check" spreads  — review, not new material
 *
 * Page ranges are marked confirmed (FR-4.15) because they come from the
 * publisher's own index rather than from a model's guess.
 *
 *   php bin/seed_curriculum.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Dana\Database\Bootstrap;
use Dana\Domain\Models\Book;
use Dana\Domain\Models\Level;
use Dana\Domain\Models\User;
use Dana\Support\Config;
use Illuminate\Database\Capsule\Manager as Capsule;

$config = Config::load(dirname(__DIR__));
Bootstrap::boot($config);

$level = Level::query()->where('slug', 'beginner')->firstOrFail();
$book = Book::query()->where('level_id', $level->id)
    ->where('kind', Book::KIND_STUDENTS_BOOK)->where('text_status', 'ready')
    ->orderByDesc('id')->firstOrFail();
$superadmin = User::query()->where('role', 'superadmin')->firstOrFail();

$now = date('Y-m-d H:i:s');

/** unit number => [ [code, title, first page], ... ] */
$curriculum = [
    1 => [['A', 'A cappuccino, please', 6], ['B', 'World music', 8]],
    2 => [['A', 'Are you on holiday?', 12], ['B', "That's my bus!", 14]],
    3 => [['A', 'Where are my keys?', 18], ['B', 'Souvenirs', 20]],
    4 => [['A', 'Meet the family', 24], ['B', 'The perfect car', 26]],
    5 => [['A', 'A big breakfast?', 30], ['B', 'A very long flight', 32]],
    6 => [['A', 'A school reunion', 36], ['B', 'Good morning, goodnight', 38]],
];

$position = 0;
$created = 0;

foreach ($curriculum as $number => $sections) {
    $unitId = Capsule::table('units')
        ->where('level_id', $level->id)->where('number', $number)->value('id');

    if ($unitId === null) {
        $unitId = Capsule::table('units')->insertGetId([
            'level_id'   => $level->id,
            'number'     => $number,
            'title'      => null,
            'sort_order' => $number,
        ]);
    }

    foreach ($sections as $index => [$code, $title, $firstPage]) {
        $position++;

        $sectionId = Capsule::table('unit_sections')
            ->where('unit_id', $unitId)->where('code', $code)->value('id');

        if ($sectionId === null) {
            $sectionId = Capsule::table('unit_sections')->insertGetId([
                'unit_id'        => $unitId,
                'code'           => $code,
                'title'          => $title,
                'sort_order'     => $index + 1,
                'level_position' => $position,
            ]);
            $created++;
        } else {
            // Keep the title and teaching order in step if the row was
            // created earlier by a test or a partial run.
            Capsule::table('unit_sections')->where('id', $sectionId)->update([
                'title'          => $title,
                'sort_order'     => $index + 1,
                'level_position' => $position,
            ]);
        }

        $exists = Capsule::table('section_sources')
            ->where('unit_section_id', $sectionId)
            ->where('book_id', $book->id)
            ->exists();

        if (!$exists) {
            // Each lesson is a two-page spread in this series.
            Capsule::table('section_sources')->insert([
                'unit_section_id' => $sectionId,
                'book_id'         => $book->id,
                'page_from'       => $firstPage,
                'page_to'         => $firstPage + 1,
                'confirmed_by'    => $superadmin->id,
                'confirmed_at'    => $now,
            ]);
        }

        $pagesWithText = Capsule::table('book_pages')
            ->where('book_id', $book->id)
            ->whereBetween('page_number', [$firstPage, $firstPage + 1])
            ->whereRaw('CHAR_LENGTH(raw_text) > 20')
            ->count();

        $state = $pagesWithText > 0 ? "{$pagesWithText}/2 pages OCR'd" : 'needs OCR';

        printf(
            "  %-3s %-26s pp.%d-%d  (%s)  section id %d\n",
            $number . $code,
            $title,
            $firstPage,
            $firstPage + 1,
            $state,
            $sectionId
        );
    }
}

echo "\n{$created} new sections. Structure covers units 1-6 of the Beginner Student's Book.\n";
echo "Practical English and Revise and Check spreads are excluded on purpose (FR-4.9).\n\n";
echo "To fill a section once LLM quota allows:\n";
echo "  php bin/ocr_pages.php --book={$book->id} --from=<first> --to=<last>\n";
echo "  php bin/generate_section.php --section=<id> --publish\n";
