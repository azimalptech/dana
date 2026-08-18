<?php

declare(strict_types=1);

/**
 * OCR a page range of a scanned book into `book_pages`.
 *
 * The supplied books have no text layer, so this replaces the normal
 * extraction step. Each page image goes to the vision model once, and
 * the result is stored — generation never re-reads the PDF or re-OCRs.
 *
 *   php bin/ocr_pages.php --book=1 --from=4 --to=9
 *
 * Free-tier friendly: pages already holding text are skipped, so a run
 * interrupted by a 429 can simply be repeated.
 */

ini_set('memory_limit', '1024M');

require __DIR__ . '/../vendor/autoload.php';

use Dana\Content\Ingestion\PageImageExtractor;
use Dana\Content\Llm\GeminiProvider;
use Dana\Content\Llm\LlmRateLimitException;
use Dana\Database\Bootstrap;
use Dana\Domain\Models\Book;
use Dana\Support\Config;
use Dana\Support\LoggerFactory;
use Illuminate\Database\Capsule\Manager as Capsule;

$config = Config::load(dirname(__DIR__));
Bootstrap::boot($config);
$log = LoggerFactory::get($config, 'worker');

$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)=(.*)$/s', $arg, $m) === 1) {
        $args[$m[1]] = $m[2];
    }
}

$bookId = (int) ($args['book'] ?? 0);
$from = (int) ($args['from'] ?? 1);
$to = (int) ($args['to'] ?? $from);

$book = Book::query()->find($bookId);

if ($book === null) {
    fwrite(STDERR, "Book {$bookId} not found.\n");
    exit(1);
}

$imageDirectory = dirname(__DIR__, 2) . '/storage/pages/book-' . $book->id;
$images = (new PageImageExtractor())->extract($book->file_path, $imageDirectory);

echo "Book {$book->id}: {$book->title}\n";
echo 'Embedded page images: ' . count($images) . "\n\n";

if ($images === []) {
    fwrite(STDERR, "No page images found — this PDF may not be a scan after all.\n");
    exit(1);
}

$instruction = <<<'PROMPT'
Transcribe this textbook page exactly as printed.

Rules:
  - Output plain text only. No commentary, no markdown fences.
  - Preserve the reading order: headings, then each exercise in order.
  - Keep exercise numbers and letters (1, 2, a, b, ...) as printed.
  - Transcribe every full sentence you can read, verbatim.
  - Mark a section you cannot read as [unreadable].
  - If the page is a picture with no text, output exactly: [no text]
PROMPT;

$provider = new GeminiProvider(
    apiKey: (string) $config->get('GEMINI_API_KEY'),
    model: (string) $config->get('GEMINI_MODEL_GENERATE', 'gemini-3.5-flash'),
);

$done = 0;
$skipped = 0;
$inTokens = 0;
$outTokens = 0;

for ($page = $from; $page <= $to; $page++) {
    $existing = Capsule::table('book_pages')
        ->where('book_id', $book->id)
        ->where('page_number', $page)
        ->first();

    if ($existing !== null && mb_strlen((string) $existing->raw_text) > 20) {
        echo "  p{$page}: already has text, skipping\n";
        $skipped++;
        continue;
    }

    $image = $images[$page - 1] ?? null;

    if ($image === null) {
        echo "  p{$page}: no image at that index\n";
        continue;
    }

    try {
        $result = $provider->transcribeImage($image, $instruction);
    } catch (LlmRateLimitException $e) {
        echo "\nRATE LIMITED at page {$page}. Re-run this command later to continue.\n";
        $log->warning('ocr rate limited', ['book_id' => $book->id, 'page' => $page]);
        break;
    } catch (Throwable $e) {
        echo "  p{$page}: FAILED - {$e->getMessage()}\n";
        continue;
    }

    Capsule::table('book_pages')->updateOrInsert(
        ['book_id' => $book->id, 'page_number' => $page],
        ['raw_text' => $result->text]
    );

    $inTokens += $result->inputTokens;
    $outTokens += $result->outputTokens;
    $done++;

    $preview = mb_substr(preg_replace('/\s+/u', ' ', $result->text) ?? '', 0, 70);
    echo "  p{$page}: " . mb_strlen($result->text) . " chars | {$preview}\n";

    // Gentle on a free-tier key.
    usleep(1_200_000);
}

echo "\nOCR complete: {$done} transcribed, {$skipped} skipped.\n";
echo "Tokens: {$inTokens} in / {$outTokens} out.\n";

$log->info('ocr run', [
    'book_id' => $book->id,
    'from'    => $from,
    'to'      => $to,
    'done'    => $done,
]);
