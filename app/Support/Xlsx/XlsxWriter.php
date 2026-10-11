<?php

namespace App\Support\Xlsx;

use ZipArchive;

/**
 * Minimal pure-PHP .xlsx writer (no external dependency).
 *
 * Builds a valid Office Open XML workbook from rows of scalar cells, writing
 * every string cell as an inline string («inlineStr») so no shared-strings
 * table is needed. Requires ext-zip (ZipArchive).
 *
 * Caveat: no styles/formatting are emitted — useful for data templates and
 * exports, not for pixel-perfect formatting.
 */
final class XlsxWriter
{
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const NS_PKG_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const NS_CT = 'http://schemas.openxmlformats.org/package/2006/content-types';

    private const CONTENT_TYPE_SHEET = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private const CONTENT_TYPE_WORKBOOK = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';

    /**
     * Build a .xlsx binary string.
     *
     * @param  array<int, array{name: string, rows: array<int, array<int, string|int|float|null>>}>  $sheets
     */
    public static function build(array $sheets): string
    {
        $sheetCount = max(1, count($sheets));

        $tmp = (string) tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;

        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create XLSX package.');
        }

        $zip->addFromString('[Content_Types].xml', self::contentTypes($sheetCount));
        $zip->addFromString('_rels/.rels', self::rootRels());
        $zip->addFromString('xl/workbook.xml', self::workbook($sheets));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRels($sheetCount));

        foreach ($sheets as $i => $sheet) {
            $sheetNum = $i + 1;
            $rows = $sheet['rows'];
            if (count($rows) === 0) {
                $rows = [[]];
            }
            $zip->addFromString("xl/worksheets/sheet{$sheetNum}.xml", self::worksheet($rows));
        }

        $zip->close();

        $content = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $content;
    }

    /**
     * @param  array<int, array{name: string, rows: array<int, array<int, string|int|float|null>>}>  $sheets
     */
    private static function workbook(array $sheets): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<workbook xmlns="'.self::NS_MAIN.'" xmlns:r="'.self::NS_REL.'">';
        $xml .= '<sheets>';

        foreach ($sheets as $i => $sheet) {
            $name = htmlspecialchars((string) $sheet['name'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $xml .= '<sheet name="'.$name.'" sheetId="'.($i + 1).'" r:id="rId'.($i + 1).'"/>';
        }

        $xml .= '</sheets></workbook>';

        return $xml;
    }

    private static function contentTypes(int $sheetCount): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<Types xmlns="'.self::NS_CT.'">';
        $xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $xml .= '<Default Extension="xml" ContentType="application/xml"/>';
        $xml .= '<Override PartName="/xl/workbook.xml" ContentType="'.self::CONTENT_TYPE_WORKBOOK.'"/>';

        for ($i = 1; $i <= $sheetCount; $i++) {
            $xml .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="'.self::CONTENT_TYPE_SHEET.'"/>';
        }

        $xml .= '</Types>';

        return $xml;
    }

    private static function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="'.self::NS_PKG_REL.'">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private static function workbookRels(int $sheetCount): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<Relationships xmlns="'.self::NS_PKG_REL.'">';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $xml .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }

        $xml .= '</Relationships>';

        return $xml;
    }

    /**
     * @param  array<int, array<int, string|int|float|null>>  $rows
     */
    private static function worksheet(array $rows): string
    {
        $maxCols = 1;
        foreach ($rows as $row) {
            $maxCols = max($maxCols, count($row));
        }

        $cols = '<cols><col min="1" max="'.$maxCols.'" width="22" customWidth="1"/></cols>';

        $rowsXml = '';
        foreach ($rows as $r => $row) {
            $rowNo = $r + 1;
            $cells = '';
            $row = array_values($row);

            for ($c = 0, $n = count($row); $c < $n; $c++) {
                $value = $row[$c];
                if ($value === null) {
                    continue;
                }

                $ref = self::columnLetter($c + 1).$rowNo;

                if (is_string($value)) {
                    $text = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    $cells .= '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.$text.'</t></is></c>';
                } else {
                    $num = is_float($value) ? rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.') : (string) $value;
                    $cells .= '<c r="'.$ref.'"><v>'.$num.'</v></c>';
                }
            }

            $rowsXml .= '<row r="'.$rowNo.'">'.$cells.'</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="'.self::NS_MAIN.'">'
            .$cols
            .'<sheetData>'.$rowsXml.'</sheetData>'
            .'</worksheet>';
    }

    private static function columnLetter(int $index): string
    {
        $letter = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $letter = chr(65 + $mod).$letter;
            $index = intdiv($index - 1, 26);
        }

        return $letter;
    }
}
