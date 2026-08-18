<?php

declare(strict_types=1);

/**
 * Stage 1-2 of the content pipeline (docs/05): register a book and
 * extract its text one page at a time into `book_pages`.
 *
 * Extraction happens ONCE per book. Generation never re-reads the PDF —
 * it works from book_pages, restricted to the page ranges mapped to the
 * section being generated (FR-4.2).
 *
 *   php bin/ingest_book.php --file="C:\...\Student's Book.pdf" \
 *       --level=Beginner --set="English File Beginner 4e" --kind=students_book
 */

ini_set('memory_limit', '1024M');

require __DIR__ . '/../vendor/autoload.php';

use Dana\Database\Bootstrap;
use Dana\Domain\Models\Book;
use Dana\Domain\Models\BookSet;
use Dana\Domain\Models\Level;
use Dana\Support\Config;
use Illuminate\Database\Capsule\Manager as Capsule;
use Smalot\PdfParser\Parser;

$config = Config::load(dirname(__DIR__));
Bootstrap::boot($config);

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)=(.*)$/s', $arg, $m) === 1) {
        $args[$m[1]] = $m[2];
    }
}

$file = $args['file'] ?? '';
$levelName = $args['level'] ?? 'Beginner';
$setName = $args['set'] ?? ($levelName . ' set');
$kind = $args['kind'] ?? Book::KIND_STUDENTS_BOOK;

if (!is_file($file)) {
    fwrite(STDERR, "File not found: {$file}\n");
    exit(1);
}

$now = date('Y-m-d H:i:s');

$level = Level::query()->where('name', $levelName)->first();
if ($level === null) {
    $level = new Level();
    $level->name = $levelName;
    $level->slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $levelName) ?? 'level');
    $level->sort_order = (int) Level::query()->max('sort_order') + 1;
    $level->is_active = true;
    $level->created_at = $now;
    $level->updated_at = $now;
    $level->save();
    echo "Created level: {$level->name}\n";
}

$bookSet = BookSet::query()->where('level_id', $level->id)->where('name', $setName)->first();
if ($bookSet === null) {
    $bookSet = new BookSet();
    $bookSet->level_id = $level->id;
    $bookSet->name = $setName;
    $bookSet->is_active = true;
    $bookSet->created_at = $now;
    $bookSet->updated_at = $now;
    $bookSet->save();
    echo "Created book set: {$bookSet->name}\n";
}

// Books live outside the web root (FR-3.11). Copy rather than reference
// the original, so the source can be moved or deleted safely.
$storage = dirname(__DIR__, 2) . '/storage/books';
if (!is_dir($storage)) {
    mkdir($storage, 0775, true);
}

$destination = $storage . '/' . $level->slug . '-' . $kind . '.pdf';
if (realpath($file) !== realpath($destination)) {
    copy($file, $destination);
}

$book = Book::query()->where('book_set_id', $bookSet->id)->where('kind', $kind)->first() ?? new Book();
$book->book_set_id = $bookSet->id;
$book->level_id = $level->id;
$book->kind = $kind;
$book->title = basename($file);
$book->file_path = $destination;
$book->text_status = 'extracting';
$book->created_at ??= $now;
$book->updated_at = $now;
$book->save();

echo "Extracting {$book->title} ...\n";

try {
    $pages = (new Parser())->parseFile($destination)->getPages();
} catch (Throwable $e) {
    $book->text_status = 'failed';
    $book->save();
    fwrite(STDERR, "Extraction failed: {$e->getMessage()}\n");
    exit(1);
}

Capsule::table('book_pages')->where('book_id', $book->id)->delete();

$empty = [];
$batch = [];

foreach ($pages as $index => $page) {
    $number = $index + 1;
    $text = trim($page->getText());

    // A page yielding almost nothing is an image. Flagged now rather
    // than discovered later as a silently empty generation run.
    if (mb_strlen($text) < 20) {
        $empty[] = $number;
    }

    $batch[] = ['book_id' => $book->id, 'page_number' => $number, 'raw_text' => $text];

    if (count($batch) === 25) {
        Capsule::table('book_pages')->insert($batch);
        $batch = [];
        echo "  {$number} pages\r";
    }
}

if ($batch !== []) {
    Capsule::table('book_pages')->insert($batch);
}

$book->page_count = count($pages);
$book->text_status = 'ready';
$book->save();

echo "\nExtracted " . count($pages) . " pages (book id {$book->id}).\n";

if ($empty !== []) {
    $list = implode(', ', array_slice($empty, 0, 20));
    $more = count($empty) > 20 ? ' …' : '';
    echo 'WARNING: ' . count($empty) . " page(s) yielded almost no text — likely images: {$list}{$more}\n";
    echo "Sections mapped to those pages will have nothing to generate from.\n";
}
