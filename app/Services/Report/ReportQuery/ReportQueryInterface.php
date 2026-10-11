<?php

namespace App\Services\Report\ReportQuery;

/**
 * Contract for all report query classes.
 *
 * Each concrete report implements this interface. The query is responsible
 * for executing the database read and returning a **normalised dataset** —
 * it must NEVER mutate any business data.
 *
 * Lifecycle: ReportService dispatches → query->execute() → Exporter formats.
 */
interface ReportQueryInterface
{
    /**
     * Execute the report query with the given filters.
     *
     * @return array{title: string, columns: array<int, array{key: string, label: string, type?: string}>, rows: array<int, array<string, mixed>>, summary?: array<string, mixed>}
     */
    public function execute(): array;
}
