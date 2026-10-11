<?php

namespace App\Services\Report\Export;

/**
 * Generic CSV export adapter.
 *
 * Converts a normalised report dataset into CSV output using PHP's
 * built-in fputcsv — no external dependencies required.
 */
class CsvExport
{
    /**
     * Generate CSV content from a report dataset.
     *
     * @param  array{title: string, columns: array, rows: array}  $dataset
     * @return string Raw CSV content.
     */
    public function generate(array $dataset): string
    {
        $output = fopen('php://temp', 'r+');

        // Header row from column definitions.
        $headers = array_map(
            fn (array $col): string => $col['label'],
            $dataset['columns'],
        );
        fputcsv($output, $headers);

        // Data rows.
        $keys = array_map(
            fn (array $col): string => $col['key'],
            $dataset['columns'],
        );

        foreach ($dataset['rows'] as $row) {
            $line = [];
            foreach ($keys as $key) {
                $line[] = $row[$key] ?? '';
            }
            fputcsv($output, $line);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv;
    }

    /**
     * Return the MIME type for CSV files.
     */
    public function mimeType(): string
    {
        return 'text/csv';
    }

    /**
     * Return the file extension for CSV files.
     */
    public function extension(): string
    {
        return 'csv';
    }
}
