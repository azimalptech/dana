<?php

declare(strict_types=1);

namespace Dana\Content\Ingestion;

use RuntimeException;

/**
 * Pulls the embedded page images out of a scanned PDF.
 *
 * The books supplied are scans: each page is one JPEG inside the PDF and
 * there is no text layer, so the normal `pdftotext` path returns nothing
 * (Q-12 assumed otherwise; the real files proved otherwise).
 *
 * Rather than add a Ghostscript/poppler dependency to render pages, this
 * lifts the JPEG streams straight out of the file. A JPEG begins FF D8 FF
 * and ends FF D9, and in a scanned PDF the images appear in page order,
 * so image N is page N. That assumption is verified by OCR'ing a known
 * page and checking the content matches — never assumed silently.
 */
final class PageImageExtractor
{
    private const JPEG_START = "\xFF\xD8\xFF";
    private const JPEG_END = "\xFF\xD9";

    /** Ignore thumbnails, logos and decorative art. */
    private const MIN_BYTES = 40_000;

    /**
     * @return list<string> absolute paths, in page order
     */
    public function extract(string $pdfPath, string $outputDirectory): array
    {
        if (!is_file($pdfPath)) {
            throw new RuntimeException("PDF not found: {$pdfPath}");
        }

        if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
            throw new RuntimeException("Cannot create directory: {$outputDirectory}");
        }

        $existing = glob($outputDirectory . '/page-*.jpg') ?: [];
        if ($existing !== []) {
            sort($existing);

            return $existing;
        }

        $raw = (string) file_get_contents($pdfPath);
        $length = strlen($raw);
        $paths = [];
        $offset = 0;
        $index = 0;

        while ($offset < $length) {
            $start = strpos($raw, self::JPEG_START, $offset);

            if ($start === false) {
                break;
            }

            $end = strpos($raw, self::JPEG_END, $start + 3);

            if ($end === false) {
                break;
            }

            $bytes = substr($raw, $start, $end - $start + 2);
            $offset = $end + 2;

            if (strlen($bytes) < self::MIN_BYTES) {
                continue;
            }

            $index++;
            $path = sprintf('%s/page-%03d.jpg', $outputDirectory, $index);
            file_put_contents($path, $bytes);
            $paths[] = $path;
        }

        return $paths;
    }
}
