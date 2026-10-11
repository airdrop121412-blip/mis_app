<?php

namespace App\Support\Xlsx;

use DOMDocument;
use DOMXPath;
use ZipArchive;

/**
 * Minimal pure-PHP .xlsx reader (no external dependency).
 *
 * Parses every worksheet of a .xlsx file into 2-D arrays of scalar values,
 * keyed by sheet name (workbook order preserved). Handles inline strings,
 * the shared-strings table, numbers, booleans, and empty cells. Date cells
 * come through as their raw Excel serial number (the caller converts them
 * based on column semantics).
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
        try {
            $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
            if ($sharedXml !== false) {
                $sharedTmp = self::writeTemp($sharedXml);
            }

            $out = [];
            foreach (self::sheetList($zip) as $sheet) {
                $xml = $zip->getFromName('xl/worksheets/sheet'.$sheet['index'].'.xml');
                if ($xml === false) {
                    continue;
                }
                $out[$sheet['name']] = self::parseSheetXml($xml, $sharedTmp);
            }

            return $out;
        } finally {
            if ($sharedTmp !== '') {
                @unlink($sharedTmp);
            }
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
     * @return array<int, array<int, string|int|float|bool|null>>
     */
    private static function parseSheetXml(string $sheetXml, string $sharedPath): array
    {
        $shared = self::sharedStrings($sharedPath);

        $doc = new DOMDocument;
        $doc->loadXML($sheetXml);
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('m', self::NS_MAIN);

        $data = [];

        /** @var \DOMNodeList<\DOMElement> $rowNodes */
        $rowNodes = $xpath->query('//m:sheetData/m:row');

        foreach ($rowNodes as $rowNode) {
            $cells = $xpath->query('m:c', $rowNode) ?: [];
            $row = [];

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

            if (count($row) === 0) {
                continue;
            }

            $data[] = $row;
        }

        return $data;
    }

    /**
     * @return list<string>
     */
    private static function sharedStrings(string $path): array
    {
        if ($path === '' || ! is_file($path)) {
            return [];
        }

        $doc = new DOMDocument;
        @$doc->loadXML((string) file_get_contents($path));
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('m', self::NS_MAIN);

        $items = [];
        foreach ($xpath->query('//m:si') as $si) {
            $parts = [];
            foreach ($xpath->query('.//m:t', $si) as $t) {
                $parts[] = $t->textContent;
            }
            $items[] = implode('', $parts);
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

    private static function writeTemp(string $content): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'xlsxr');
        file_put_contents($path, $content);

        return $path;
    }
}
