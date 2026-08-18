<?php

declare(strict_types=1);

namespace Dana\Support\Xlsx;

use DOMDocument;
use DOMElement;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Minimal SpreadsheetML reader for the xlsx import (FR-13.15).
 *
 * Reads what real editors actually save back, not just what our own
 * XlsxWriter emits: shared strings AND inline strings (plain or
 * rich-text runs), cells omitted mid-row (restored to '' from their
 * A1-style refs so columns stay aligned), and rows omitted mid-sheet
 * (kept as [] so a list index + 1 is always the row number the admin
 * sees in Excel — the import's row-level error messages depend on
 * that). Every value comes back as a trimmed string; rows that are
 * entirely empty at the bottom of a sheet are dropped.
 *
 * Deliberately NOT a general xlsx parser: no formula evaluation, no
 * number formats, no date arithmetic — the import sheets carry plain
 * text and plain numbers only.
 */
final class XlsxReader
{
    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** @param array<string, list<list<string>>> $sheets */
    private function __construct(private readonly array $sheets)
    {
    }

    public static function open(string $path): self
    {
        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException("Not a readable xlsx file: {$path}");
        }

        try {
            $shared = self::sharedStrings($zip);
            $sheets = [];

            foreach (self::sheetTargets($zip) as $name => $target) {
                $sheets[$name] = self::worksheet(self::part($zip, $target), $shared);
            }

            return new self($sheets);
        } finally {
            $zip->close();
        }
    }

    /**
     * Every sheet in workbook order.
     *
     * @return array<string, list<list<string>>> sheet name => rows of trimmed cell values
     */
    public function sheets(): array
    {
        return $this->sheets;
    }

    // ------------------------------------------------------------ internals

    /** @return array<string, string> sheet name => worksheet part name */
    private static function sheetTargets(ZipArchive $zip): array
    {
        $rels = self::dom(self::part($zip, 'xl/_rels/workbook.xml.rels'));
        $targets = [];

        foreach ($rels->getElementsByTagNameNS('*', 'Relationship') as $rel) {
            $targets[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
        }

        $out = [];

        foreach (self::dom(self::part($zip, 'xl/workbook.xml'))->getElementsByTagNameNS('*', 'sheet') as $sheet) {
            $target = $targets[$sheet->getAttributeNS(self::REL_NS, 'id')] ?? null;

            if ($target === null) {
                throw new RuntimeException(
                    "The xlsx workbook lists sheet '{$sheet->getAttribute('name')}' without a worksheet part."
                );
            }

            // Targets are workbook-relative ("worksheets/sheet1.xml")
            // unless written package-absolute ("/xl/worksheets/sheet1.xml").
            $out[$sheet->getAttribute('name')] = str_starts_with($target, '/')
                ? substr($target, 1)
                : 'xl/' . $target;
        }

        return $out;
    }

    /** @return list<string> the sst by index; [] when the part is absent */
    private static function sharedStrings(ZipArchive $zip): array
    {
        $raw = $zip->getFromName('xl/sharedStrings.xml');

        if ($raw === false) {
            return []; // inline-string-only files carry no sst at all
        }

        $strings = [];

        foreach (self::dom($raw)->getElementsByTagNameNS('*', 'si') as $si) {
            // A plain <t>, or rich-text runs <r><t>…</t></r> — concatenating
            // every <t> under the entry covers both shapes.
            $text = '';

            foreach ($si->getElementsByTagNameNS('*', 't') as $t) {
                $text .= $t->textContent;
            }

            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * Streams one worksheet row by row — a whole-database export is too
     * big to hold as a DOM tree, a single row never is.
     *
     * @param list<string> $shared
     * @return list<list<string>>
     */
    private static function worksheet(string $xml, array $shared): array
    {
        $reader = new XMLReader();
        $before = libxml_use_internal_errors(true);

        try {
            if (!$reader->XML($xml, 'UTF-8', LIBXML_NONET)) {
                throw new RuntimeException('Unreadable worksheet part inside the xlsx file.');
            }

            $rows = [];

            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                // Rows carry their own numbers and may skip some; pad the
                // gaps so index + 1 stays the row number Excel displays.
                $rowNum = (int) $reader->getAttribute('r');

                if ($rowNum <= count($rows)) {
                    $rowNum = count($rows) + 1;
                }

                $element = $reader->expand();

                if (!$element instanceof DOMElement) {
                    throw new RuntimeException('Unreadable worksheet row inside the xlsx file.');
                }

                while (count($rows) < $rowNum - 1) {
                    $rows[] = [];
                }

                $rows[] = self::row($element, $shared);
            }

            if (libxml_get_errors() !== []) {
                throw new RuntimeException('Malformed worksheet XML inside the xlsx file.');
            }

            // An admin deleting rows at the bottom leaves them behind as
            // empty <row> stubs — those are not content.
            while ($rows !== [] && array_filter($rows[count($rows) - 1], static fn (string $c): bool => $c !== '') === []) {
                array_pop($rows);
            }

            return $rows;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($before);
            $reader->close();
        }
    }

    /**
     * @param list<string> $shared
     * @return list<string>
     */
    private static function row(DOMElement $rowElement, array $shared): array
    {
        $row = [];
        $next = 0;

        foreach ($rowElement->getElementsByTagNameNS('*', 'c') as $cell) {
            $ref = $cell->getAttribute('r');
            $col = $ref !== '' ? self::columnIndex($ref) : $next;

            if ($col < 0) {
                $col = $next;
            }

            // A missing cell mid-row ("A1 then C1") reads as '' in between.
            for ($i = count($row); $i < $col; $i++) {
                $row[$i] = '';
            }

            $row[$col] = trim(self::cellValue($cell, $shared));
            $next = $col + 1;
        }

        return array_values($row);
    }

    /** @param list<string> $shared */
    private static function cellValue(DOMElement $cell, array $shared): string
    {
        if ($cell->getAttribute('t') === 'inlineStr') {
            $text = '';

            foreach ($cell->getElementsByTagNameNS('*', 't') as $t) {
                $text .= $t->textContent;
            }

            return $text;
        }

        $v = $cell->getElementsByTagNameNS('*', 'v')->item(0);

        if ($v === null) {
            return '';
        }

        if ($cell->getAttribute('t') === 's') {
            return $shared[(int) trim($v->textContent)] ?? '';
        }

        // n (default), str, b, e: the literal <v> text is the value.
        return $v->textContent;
    }

    /** "C5" => 2. -1 when the ref carries no column letters at all. */
    private static function columnIndex(string $ref): int
    {
        $col = 0;
        $len = strlen($ref);

        for ($i = 0; $i < $len; $i++) {
            $byte = ord($ref[$i]);

            if ($byte < 65 || $byte > 90) {
                break; // the digits of the row number begin
            }

            $col = $col * 26 + ($byte - 64);
        }

        return $col - 1;
    }

    private static function part(ZipArchive $zip, string $name): string
    {
        $raw = $zip->getFromName($name);

        if ($raw === false) {
            throw new RuntimeException("The xlsx file is missing its {$name} part.");
        }

        return $raw;
    }

    private static function dom(string $xml): DOMDocument
    {
        $dom = new DOMDocument();

        if (!@$dom->loadXML($xml, LIBXML_NONET)) {
            throw new RuntimeException('Malformed XML part inside the xlsx file.');
        }

        return $dom;
    }
}
