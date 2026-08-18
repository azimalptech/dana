<?php

declare(strict_types=1);

/**
 * The hand-rolled xlsx library (FR-13.15), writer to reader and back.
 *
 * No database, no framework. Builds a workbook with XlsxWriter, opens
 * it again with XlsxReader and checks that everything survived: unicode
 * across four scripts (ä ň ы 中), XML-hostile characters (& < > " '),
 * ints and floats, three sheets, a 1000-row sheet streamed from a
 * generator. Then reads a hand-built worksheet in the shapes OTHER
 * editors save — inline strings, rich-text runs, gap cells, a skipped
 * row, empty trailing rows — because imports come back from Excel and
 * LibreOffice, not from our own writer.
 *
 *   C:/xampp/php/php.exe tests/xlsx_lib_test.php
 *
 * Works entirely inside a fresh temp directory and removes it after.
 */

require __DIR__ . '/../vendor/autoload.php';

use Dana\Support\Xlsx\XlsxReader;
use Dana\Support\Xlsx\XlsxWriter;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $label . ($detail !== '' ? "  ({$detail})" : '') . PHP_EOL;
}

$dir = sys_get_temp_dir() . '/dana_xlsx_test_' . getmypid();

if (!is_dir($dir) && !mkdir($dir)) {
    fwrite(STDERR, "Cannot create temp dir {$dir}\n");
    exit(1);
}

try {
    echo "=== writer: three sheets, unicode, hostile characters ===\n";

    $writer = new XlsxWriter();
    $writer->addSheet('Görnüş ы 中', ['ID', 'Term & Note', 'Bahasy'], [
        [1, 'söz "quoted" <tag>', 3.5],
        [2, "it's & that's", -7],
        [3, 'ä ň ы 中 ÄŇЫ', 0],
        [4, null, 'after a gap'],
        [5, 'gaýtalanýan', 1],
        [6, 'gaýtalanýan', 2],
    ]);

    // 1000 rows through a generator — addSheet must stream, not buffer.
    $thousand = static function (): Generator {
        for ($i = 1; $i <= 1000; $i++) {
            yield ["term {$i}", $i, $i / 2, "terjime ы {$i}"];
        }
    };
    $writer->addSheet('Vocabulary', ['term_en', 'n', 'half', 'translation'], $thousand());
    $writer->addSheet('Boş', ['only header'], []);

    $path = $dir . '/roundtrip.xlsx';
    $writer->saveTo($path);
    check('saveTo writes a file', is_file($path) && filesize($path) > 0);

    $bytes = $writer->toString();
    check('toString returns zip bytes', str_starts_with($bytes, 'PK'), strlen($bytes) . ' bytes');

    echo "\n=== the container is real SpreadsheetML ===\n";

    $zip = new ZipArchive();
    check('the package opens as a zip', $zip->open($path) === true);

    $types = (string) $zip->getFromName('[Content_Types].xml');
    check(
        'content types cover workbook, sheet3, styles, sharedStrings',
        str_contains($types, '/xl/workbook.xml')
        && str_contains($types, '/xl/worksheets/sheet3.xml')
        && str_contains($types, '/xl/styles.xml')
        && str_contains($types, '/xl/sharedStrings.xml')
    );
    check('package rels present', $zip->getFromName('_rels/.rels') !== false);
    check('workbook rels present', $zip->getFromName('xl/_rels/workbook.xml.rels') !== false);

    $sheet1 = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
    check('header row is frozen', str_contains($sheet1, 'state="frozen"'));
    check('header cells carry the bold style', str_contains($sheet1, 'r="A1" t="s" s="1"'));
    check('data cells do not', str_contains($sheet1, '<c r="B2" t="s"><v>'));

    $styles = (string) $zip->getFromName('xl/styles.xml');
    check('styles define the bold font', str_contains($styles, '<b/>'));

    $sst = (string) $zip->getFromName('xl/sharedStrings.xml');
    check('repeated strings are interned once', substr_count($sst, 'gaýtalanýan') === 1);
    check('total count exceeds uniqueCount', (bool) preg_match('/count="(\d+)" uniqueCount="(\d+)"/', $sst, $m) && (int) $m[1] > (int) $m[2]);
    $zip->close();

    echo "\n=== reader: the round trip ===\n";

    $sheets = XlsxReader::open($path)->sheets();
    check(
        'three sheets come back in workbook order',
        array_keys($sheets) === ['Görnüş ы 中', 'Vocabulary', 'Boş'],
        implode(', ', array_keys($sheets))
    );

    $s1 = $sheets['Görnüş ы 中'] ?? [];
    check('header row survives', ($s1[0] ?? null) === ['ID', 'Term & Note', 'Bahasy']);
    check('quotes and angle brackets survive', ($s1[1] ?? null) === ['1', 'söz "quoted" <tag>', '3.5']);
    check('apostrophes, ampersands, negatives survive', ($s1[2] ?? null) === ['2', "it's & that's", '-7']);
    check('unicode survives byte-exact', ($s1[3] ?? null) === ['3', 'ä ň ы 中 ÄŇЫ', '0']);
    check('a null cell reads back as an aligned gap', ($s1[4] ?? null) === ['4', '', 'after a gap'], json_encode($s1[4] ?? null));
    check('seven rows in all', count($s1) === 7);

    $vocab = $sheets['Vocabulary'] ?? [];
    check('1000 data rows + header', count($vocab) === 1001, 'got ' . count($vocab));
    check('row 1 with its float', ($vocab[1] ?? null) === ['term 1', '1', '0.5', 'terjime ы 1'], json_encode($vocab[1] ?? null));
    check('row 500, whole-number float collapses', ($vocab[500] ?? null) === ['term 500', '500', '250', 'terjime ы 500']);
    check('row 1000', ($vocab[1000] ?? null) === ['term 1000', '1000', '500', 'terjime ы 1000']);

    check('a sheet with no data rows is just its header', ($sheets['Boş'] ?? null) === [['only header']]);

    $streamed = $dir . '/streamed.xlsx';
    file_put_contents($streamed, $bytes);
    check('toString bytes read back identically', XlsxReader::open($streamed)->sheets() === $sheets);

    echo "\n=== reader: shapes other editors write ===\n";

    // A workbook our writer would never produce: inline strings only
    // (no sst part at all), a rich-text run split mid-word, B1 missing
    // between A1 and C1, row 2 omitted entirely, a boolean, a bare
    // number, and two flavours of trailing emptiness.
    $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $alien = $dir . '/alien.xlsx';

    $zip = new ZipArchive();
    $zip->open($alien, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString(
        '[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>'
    );
    $zip->addFromString(
        '_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="' . $rel . '/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>'
    );
    $zip->addFromString(
        'xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="' . $main . '" xmlns:r="' . $rel . '">'
        . '<sheets><sheet name="Inline" sheetId="1" r:id="rId1"/></sheets></workbook>'
    );
    $zip->addFromString(
        'xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="' . $rel . '/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>'
    );
    $zip->addFromString(
        'xl/worksheets/sheet1.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="' . $main . '"><sheetData>'
        . '<row r="1">'
        . '<c r="A1" t="inlineStr"><is><t>first</t></is></c>'
        . '<c r="C1" t="inlineStr"><is><t> padded </t></is></c>'
        . '<c r="D1" t="b"><v>1</v></c>'
        . '<c r="F1"><v>2.5</v></c>'
        . '</row>'
        . '<row r="3"><c r="B3" t="inlineStr"><is><r><t>ri</t></r><r><t>ch</t></r></is></c></row>'
        . '<row r="4"><c r="A4" t="inlineStr"><is><t>   </t></is></c></row>'
        . '<row r="5"/>'
        . '</sheetData></worksheet>'
    );
    $zip->close();

    $inline = XlsxReader::open($alien)->sheets()['Inline'] ?? [];
    check(
        'inline strings, gaps, booleans and bare numbers in one row',
        ($inline[0] ?? null) === ['first', '', 'padded', '1', '', '2.5'],
        json_encode($inline[0] ?? null)
    );
    check('an omitted row stays as [] so index + 1 = Excel row', ($inline[1] ?? null) === []);
    check('rich-text runs concatenate', ($inline[2] ?? null) === ['', 'rich'], json_encode($inline[2] ?? null));
    check('whitespace-only and stub trailing rows are dropped', count($inline) === 3, 'got ' . count($inline));

    echo "\n=== the writer refuses what xlsx cannot hold ===\n";

    try {
        (new XlsxWriter())->addSheet('bad[name]', ['x'], []);
        check('a sheet name Excel forbids is refused', false);
    } catch (InvalidArgumentException) {
        check('a sheet name Excel forbids is refused', true);
    }

    try {
        $dupes = new XlsxWriter();
        $dupes->addSheet('Sheet', ['x'], []);
        $dupes->addSheet('sheet', ['x'], []);
        check('a duplicate sheet name is refused', false);
    } catch (InvalidArgumentException) {
        check('a duplicate sheet name is refused', true);
    }

    try {
        (new XlsxWriter())->addSheet('Arrays', ['x'], [[['nested']]]);
        check('a non-scalar cell is refused, never coerced', false);
    } catch (InvalidArgumentException) {
        check('a non-scalar cell is refused, never coerced', true);
    }

    try {
        (new XlsxWriter())->toString();
        check('a workbook with no sheets is refused', false);
    } catch (RuntimeException) {
        check('a workbook with no sheets is refused', true);
    }

    try {
        file_put_contents($dir . '/not.xlsx', 'plain text, not a zip');
        XlsxReader::open($dir . '/not.xlsx');
        check('the reader refuses a non-zip file', false);
    } catch (RuntimeException) {
        check('the reader refuses a non-zip file', true);
    }
} finally {
    foreach (glob($dir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
    echo "\nTemp files removed.\n";
}

echo "{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
