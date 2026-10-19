<?php

namespace App\Support\Xlsx;

use DOMDocument;
use DOMElement;
use DOMXPath;
use XMLReader;
use ZipArchive;

/**
 * Minimal pure-PHP .xlsx reader (no external dependency).
 *
 * Parses worksheets of a .xlsx file into 2-D arrays of scalar values,
 * keyed by sheet name (workbook order preserved). Handles inline strings,
 * the shared-strings table, numbers, booleans, and empty cells. Date cells
 * come through as their raw Excel serial number (the caller converts them
 * based on column semantics).
 *
 * STREAMING: the shared-strings part and each worksheet part are read with
 * XMLReader one element at a time; individual <row> fragments are parsed
 * with a small throwaway DOM document per row. Memory stays proportional to
 * the RESULT rows of a single sheet — never to the raw XML size or the
 * number of sheets (the old DOM-everything reader needed hundreds of MB and
 * was killed by the platform's memory ceiling on large multi-sheet
 * workbooks, dropping the connection before any row was inserted).
 * readSheet() parses only the one sheet the caller asked for, which is what
 * the step-by-step import wizard needs (one request = one sheet).
 */
final class XlsxReader
{
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const NS_R_OFFICE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const NS_R_PKG = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /**
     * Read all worksheets as rows of cell values, keyed by sheet name.
     *
     * @return array<string, array<int, array<int, string|int|float|bool|null>>>
     */
    public static function readSheets(string $path): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new \InvalidArgumentException('The uploaded file is not a valid .xlsx workbook.');
        }

        $sharedTmp = '';
        $sheetTmps = [];
        try {
            $sharedTmp = self::extractEntry($zip, 'xl/sharedStrings.xml');

            $out = [];
            foreach (self::sheetList($zip) as $sheet) {
                $sheetTmp = self::extractEntry($zip, 'xl/worksheets/sheet'.$sheet['index'].'.xml');
                if ($sheetTmp === '') {
                    continue;
                }
                $sheetTmps[] = $sheetTmp;
                $out[$sheet['name']] = self::parseSheetFile($sheetTmp, $sharedTmp);
            }

            return $out;
        } finally {
            self::deleteTemp($sharedTmp);
            foreach ($sheetTmps as $sheetTmp) {
                self::deleteTemp($sheetTmp);
            }
            $zip->close();
        }
    }

    /**
     * Read ONE named worksheet as rows of cell values (0-indexed cells).
     *
     * Only this sheet's XML part is opened — every other sheet in the
     * workbook (and its raw XML) is never touched, so a 10-sheet workbook
     * costs the same as its single target sheet. Returns [] when the
     * workbook has no sheet with that name (callers treat that as
     * "sheet missing / empty", same as before).
     *
     * @return array<int, array<int, string|int|float|bool|null>>
     */
    public static function readSheet(string $path, string $sheetName): array
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw new \InvalidArgumentException('The uploaded file is not a valid .xlsx workbook.');
        }

        $sharedTmp = '';
        $sheetTmp = '';
        try {
            $sharedTmp = self::extractEntry($zip, 'xl/sharedStrings.xml');

            foreach (self::sheetList($zip) as $sheet) {
                if ($sheet['name'] !== $sheetName) {
                    continue;
                }

                $sheetTmp = self::extractEntry($zip, 'xl/worksheets/sheet'.$sheet['index'].'.xml');
                if ($sheetTmp === '') {
                    return [];
                }

                return self::parseSheetFile($sheetTmp, $sharedTmp);
            }

            return [];
        } finally {
            self::deleteTemp($sharedTmp);
            self::deleteTemp($sheetTmp);
            $zip->close();
        }
    }

    /**
     * Read the first worksheet as rows of cell values (0-indexed).
     *
     * @return array<int, array<int, string|int|float|bool|null>>
     */
    public static function read(string $path): array
    {
        $sheets = self::readSheets($path);

        return $sheets === [] ? [] : reset($sheets);
    }

    /**
     * Resolve worksheet # → name in workbook order, using workbook.xml + rels.
     *
     * @return array<int, array{name: string, index: int}>
     */
    private static function sheetList(ZipArchive $zip): array
    {
        $wkb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');

        $list = [];

        if ($wkb === false || $rels === false) {
            return [['name' => 'Sheet1', 'index' => 1]];
        }

        $wkbDoc = new DOMDocument;
        @$wkbDoc->loadXML($wkb);
        $xpath = new DOMXPath($wkbDoc);
        $xpath->registerNamespace('m', self::NS_MAIN);
        $xpath->registerNamespace('r', self::NS_R_OFFICE);

        $relsDoc = new DOMDocument;
        @$relsDoc->loadXML($rels);
        $relXpath = new DOMXPath($relsDoc);
        $relXpath->registerNamespace('r', self::NS_R_PKG);

        /** @var \DOMNodeList<\DOMElement> $sheetNodes */
        $sheetNodes = $xpath->query('//m:sheets/m:sheet');

        foreach ($sheetNodes as $sheetNode) {
            $name = $sheetNode->getAttribute('name') ?: 'Sheet1';
            $rid = $sheetNode->getAttribute('r:id') ?: $sheetNode->getAttributeNS(self::NS_R_OFFICE, 'id');

            $index = null;
            if ($rid !== '') {
                $rel = $relXpath->query('//*[@Id="'.htmlspecialchars($rid, ENT_XML1).'"]')->item(0);
                $target = $rel instanceof \DOMElement ? $rel->getAttribute('Target') : '';
                if (preg_match('/worksheets\/sheet(\d+)\.xml/', $target, $m)) {
                    $index = (int) $m[1];
                }
            }

            $list[] = ['name' => $name, 'index' => $index ?? (count($list) + 1)];
        }

        if ($list === []) {
            return [['name' => 'Sheet1', 'index' => 1]];
        }

        return $list;
    }

    /**
     * Stream-parse one worksheet part (already extracted to a temp file)
     * into rows.
     *
     * XMLReader walks the file one <row> at a time; each row's small XML
     * fragment is then parsed with a throwaway DOM document, preserving the
     * original XPath-based cell extraction semantics exactly while keeping
     * memory flat regardless of sheet size.
     *
     * @return array<int, array<int, string|int|float|bool|null>>
     */
    private static function parseSheetFile(string $xmlPath, string $sharedPath): array
    {
        $shared = self::sharedStrings($sharedPath);

        $reader = new XMLReader;
        if (! @$reader->open($xmlPath)) {
            throw new \InvalidArgumentException('The uploaded worksheet part could not be read.');
        }

        $data = [];

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    continue;
                }

                $rowXml = $reader->readOuterXML();
                if ($rowXml === '') {
                    continue;
                }

                $doc = new DOMDocument;
                if (! @$doc->loadXML($rowXml) || ! $doc->documentElement instanceof DOMElement) {
                    continue;
                }

                $xpath = new DOMXPath($doc);
                $xpath->registerNamespace('m', self::NS_MAIN);

                $row = self::parseRow($doc->documentElement, $xpath, $shared);
                if (count($row) === 0) {
                    continue;
                }

                $data[] = $row;
            }
        } finally {
            $reader->close();
        }

        return $data;
    }

    /**
     * Parse one <row> element into a 0-indexed cell array (cell semantics
     * identical to the original DOM-everything implementation).
     *
     * @param  array<int, string> $shared
     * @return array<int, string|int|float|bool|null>
     */
    private static function parseRow(DOMElement $rowNode, DOMXPath $xpath, array $shared): array
    {
        $row = [];

        /** @var \DOMNodeList<\DOMElement> $cells */
        $cells = $xpath->query('m:c', $rowNode) ?: [];

        foreach ($cells as $cell) {
            $cellRef = $cell->getAttribute('r');
            $colIndex = $cellRef !== ''
                ? self::columnIndex($cellRef)
                : count($row);

            $type = $cell->getAttribute('t');
            $value = null;

            if ($type === 'inlineStr') {
                $texts = $xpath->query('.//m:is//m:t', $cell) ?: [];
                $parts = [];
                foreach ($texts as $t) {
                    $parts[] = $t->textContent;
                }
                $value = implode('', $parts);
            } elseif ($type === 's') {
                $v = $xpath->query('m:v', $cell) ?: [];
                if ($v->length > 0) {
                    $idx = (int) trim($v->item(0)->textContent);
                    $value = $shared[$idx] ?? '';
                }
            } elseif ($type === 'b') {
                $v = $xpath->query('m:v', $cell) ?: [];
                if ($v->length > 0) {
                    $value = trim($v->item(0)->textContent) === '1';
                }
            } elseif ($type === 'str') {
                $v = $xpath->query('m:v', $cell) ?: [];
                if ($v->length > 0) {
                    $value = $v->item(0)->textContent;
                }
            } else {
                // Plain numeric cell (<v> contains the number).
                $v = $xpath->query('m:v', $cell) ?: [];
                if ($v->length > 0) {
                    $raw = trim($v->item(0)->textContent);
                    $value = self::toNumber($raw);
                }
            }

            // Preserve leading zeroes typed as strings in shared cells
            // are already strings; numeric cells stay numeric.
            while (count($row) < $colIndex) {
                $row[] = null;
            }
            $row[$colIndex] = $value === '' ? null : $value;
        }

        return $row;
    }

    /**
     * Stream the shared-strings table into a list of strings.
     *
     * The part can be tens of MB on real workbooks, so it is read with
     * XMLReader instead of DOM-loading the whole file; text nodes inside
     * each <si> are concatenated in document order (rich-text runs and
     * phonetic text included — exactly what the old './/m:t' XPath did).
     *
     * @return list<string>
     */
    private static function sharedStrings(string $path): array
    {
        if ($path === '' || ! is_file($path)) {
            return [];
        }

        $reader = new XMLReader;
        if (! @$reader->open($path)) {
            return [];
        }

        $items = [];

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'si') {
                    continue;
                }

                if ($reader->isEmptyElement) {
                    $items[] = '';

                    continue;
                }

                $depth = $reader->depth;
                $text = '';
                while ($reader->read()
                    && ! ($reader->nodeType === XMLReader::END_ELEMENT
                        && $reader->depth === $depth
                        && $reader->localName === 'si')) {
                    if ($reader->nodeType === XMLReader::TEXT
                        || $reader->nodeType === XMLReader::CDATA) {
                        $text .= $reader->value;
                    }
                }
                $items[] = $text;
            }
        } finally {
            $reader->close();
        }

        return $items;
    }

    /**
     * Convert an Excel column reference like "BC12" into a 0-based index.
     */
    private static function columnIndex(string $cellRef): int
    {
        $letters = preg_replace('/\d+$/', '', $cellRef);
        $index = 0;

        foreach (str_split($letters) as $ch) {
            $index = $index * 26 + (ord($ch) - 64);
        }

        return $index - 1;
    }

    private static function toNumber(string $raw): string|int|float
    {
        if (preg_match('/^-?\d+$/', $raw)) {
            return (int) $raw;
        }
        if (is_numeric($raw)) {
            return (float) $raw;
        }

        return $raw;
    }

    /**
     * Stream one zip entry to a temp file — the part is never materialised
     * as one giant in-memory string (worksheet XML can be tens of MB).
     */
    private static function extractEntry(ZipArchive $zip, string $name): string
    {
        $stream = $zip->getStream($name);
        if ($stream === false) {
            return '';
        }

        $tmp = (string) tempnam(sys_get_temp_dir(), 'xlsxr');
        $out = fopen($tmp, 'wb');
        if ($out === false) {
            fclose($stream);
            @unlink($tmp);

            return '';
        }

        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);

        return $tmp;
    }

    private static function deleteTemp(string $path): void
    {
        if ($path !== '') {
            @unlink($path);
        }
    }
}
