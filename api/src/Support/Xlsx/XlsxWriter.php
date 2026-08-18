<?php

declare(strict_types=1);

namespace Dana\Support\Xlsx;

use InvalidArgumentException;
use RuntimeException;
use ZipArchive;

/**
 * Minimal SpreadsheetML writer for the xlsx export (FR-13.15).
 *
 * Hand-rolled on ZipArchive on purpose: the deployment box has no
 * composer network access, and the export needs exactly two cell kinds
 * (strings and numbers), a bold frozen header row and a shared-strings
 * table — not a spreadsheet framework.
 *
 * Emits the parts Excel and LibreOffice require to open a workbook:
 * [Content_Types].xml, _rels/.rels, xl/workbook.xml (+ its rels),
 * xl/styles.xml, xl/sharedStrings.xml and one worksheet per sheet.
 *
 *     $writer = new XlsxWriter();
 *     $writer->addSheet('Vocabulary', ['term_en', 'ipa'], $rows);
 *     $writer->saveTo($path);          // or ->toString() for a response body
 *
 * Rows are consumed once at addSheet() time, so a generator streaming
 * out of the database works and is never buffered twice.
 */
final class XlsxWriter
{
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    /** @var list<array{name: string, xml: string}> */
    private array $sheets = [];

    /** @var array<string, int> shared string => its index in the sst */
    private array $stringIndex = [];

    /** Total string-cell occurrences — the sst's count attribute. */
    private int $stringCount = 0;

    /**
     * Adds one worksheet. The header row is written bold and frozen;
     * data rows follow from row 2. Cells may be strings, ints, floats
     * or null (null and '' leave the cell blank but keep the columns
     * after it aligned, because every cell carries its A1-style ref).
     *
     * @param array<int, string|int|float|null> $headerRow
     * @param iterable<array<int, string|int|float|bool|null>> $rows
     */
    public function addSheet(string $name, array $headerRow, iterable $rows): void
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 31 || preg_match('~[\\\\/:?*\[\]]~', $name)) {
            throw new InvalidArgumentException(
                "Invalid sheet name '{$name}': 1–31 characters, none of \\ / : ? * [ ]."
            );
        }

        foreach ($this->sheets as $sheet) {
            if (mb_strtolower($sheet['name']) === mb_strtolower($name)) {
                throw new InvalidArgumentException("Duplicate sheet name '{$name}'.");
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<worksheet xmlns="' . self::MAIN_NS . '">'
            . '<sheetViews><sheetView workbookViewId="0">'
            . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>'
            . '<sheetData>'
            . $this->rowXml(1, $headerRow, true);

        $r = 1;

        foreach ($rows as $row) {
            $xml .= $this->rowXml(++$r, $row, false);
        }

        $this->sheets[] = ['name' => $name, 'xml' => $xml . '</sheetData></worksheet>'];
    }

    public function saveTo(string $path): void
    {
        if ($this->sheets === []) {
            throw new RuntimeException('An xlsx workbook needs at least one sheet.');
        }

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot write the xlsx file to {$path}.");
        }

        foreach ($this->parts() as $partName => $partXml) {
            $zip->addFromString($partName, $partXml);
        }

        if (!$zip->close()) {
            throw new RuntimeException("Writing the xlsx file to {$path} failed.");
        }
    }

    /** The whole workbook as bytes — for a download response body. */
    public function toString(): string
    {
        // ZipArchive only writes to disk, so buffer through a temp file.
        $tmp = tempnam(sys_get_temp_dir(), 'dana-xlsx-');

        if ($tmp === false) {
            throw new RuntimeException('Cannot create a temp file for the xlsx buffer.');
        }

        try {
            $this->saveTo($tmp);
            $bytes = file_get_contents($tmp);

            if ($bytes === false) {
                throw new RuntimeException('Cannot read back the xlsx buffer.');
            }

            return $bytes;
        } finally {
            @unlink($tmp);
        }
    }

    // ------------------------------------------------------------ internals

    /** @param array<int, string|int|float|bool|null> $cells */
    private function rowXml(int $r, array $cells, bool $header): string
    {
        $style = $header ? ' s="1"' : '';
        $xml = '<row r="' . $r . '">';
        $col = 0;

        foreach (array_values($cells) as $value) {
            $ref = self::columnRef($col++) . $r;

            if ($value === null || $value === '') {
                continue; // blank — the next cell's ref keeps columns aligned
            }

            if (is_bool($value)) {
                $value = (int) $value;
            }

            if (is_int($value) || is_float($value)) {
                if (is_float($value) && !is_finite($value)) {
                    throw new InvalidArgumentException("Non-finite number in cell {$ref}.");
                }

                // PHP 8 renders floats locale-independently, shortest form.
                $xml .= '<c r="' . $ref . '"' . $style . '><v>' . $value . '</v></c>';
                continue;
            }

            if (!is_string($value)) {
                throw new InvalidArgumentException(
                    'Cell ' . $ref . ' is a ' . get_debug_type($value) . '; only strings and numbers fit an xlsx cell.'
                );
            }

            $xml .= '<c r="' . $ref . '" t="s"' . $style . '><v>' . $this->shared($value) . '</v></c>';
        }

        return $xml . '</row>';
    }

    /** Interns a string into the shared-strings table, returns its index. */
    private function shared(string $value): int
    {
        // XML 1.0 cannot carry control characters — strip them rather
        // than emit a file no spreadsheet will open.
        $value = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);

        $this->stringCount++;

        return $this->stringIndex[$value] ??= count($this->stringIndex);
    }

    /** 0 => A, 25 => Z, 26 => AA … */
    private static function columnRef(int $index): string
    {
        $ref = '';

        for ($n = $index; $n >= 0; $n = intdiv($n, 26) - 1) {
            $ref = chr(65 + $n % 26) . $ref;
        }

        return $ref;
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Every part of the package, part name => XML. */
    private function parts(): array
    {
        $decl = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
        $officeRel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $parts = [];

        $types = $decl
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';

        $workbook = $decl
            . '<workbook xmlns="' . self::MAIN_NS . '" xmlns:r="' . $officeRel . '"><sheets>';
        $rels = $decl
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

        foreach ($this->sheets as $i => $sheet) {
            $n = $i + 1;
            $types .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml"'
                . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $workbook .= '<sheet name="' . self::esc($sheet['name']) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $rels .= '<Relationship Id="rId' . $n . '" Type="' . $officeRel . '/worksheet"'
                . ' Target="worksheets/sheet' . $n . '.xml"/>';
            $parts['xl/worksheets/sheet' . $n . '.xml'] = $sheet['xml'];
        }

        $n = count($this->sheets);
        $parts['[Content_Types].xml'] = $types . '</Types>';
        $parts['_rels/.rels'] = $decl
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="' . $officeRel . '/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
        $parts['xl/workbook.xml'] = $workbook . '</sheets></workbook>';
        $parts['xl/_rels/workbook.xml.rels'] = $rels
            . '<Relationship Id="rId' . ($n + 1) . '" Type="' . $officeRel . '/styles" Target="styles.xml"/>'
            . '<Relationship Id="rId' . ($n + 2) . '" Type="' . $officeRel . '/sharedStrings" Target="sharedStrings.xml"/>'
            . '</Relationships>';

        // Two fonts (regular, bold), two cell formats; s="1" on header
        // cells picks the bold one. The none + gray125 fill pair is
        // boilerplate Excel insists on before it trusts a styles part.
        $parts['xl/styles.xml'] = $decl
            . '<styleSheet xmlns="' . self::MAIN_NS . '">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="2">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '</cellXfs>'
            . '</styleSheet>';

        $sst = $decl
            . '<sst xmlns="' . self::MAIN_NS . '" count="' . $this->stringCount . '"'
            . ' uniqueCount="' . count($this->stringIndex) . '">';

        foreach ($this->stringIndex as $string => $_) {
            $sst .= '<si><t xml:space="preserve">' . self::esc((string) $string) . '</t></si>';
        }

        $parts['xl/sharedStrings.xml'] = $sst . '</sst>';

        return $parts;
    }
}
