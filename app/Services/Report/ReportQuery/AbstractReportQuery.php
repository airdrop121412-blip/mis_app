<?php

namespace App\Services\Report\ReportQuery;

use Illuminate\Database\Eloquent\Builder;

/**
 * Base class for all report queries.
 *
 * Centralises common filter application (project, geo, status, date range)
 * and enforces the read-only invariant: no report query may call insert,
 * update, delete, or fire events that mutate state.
 *
 * Concrete reports extend this and implement execute() + columns().
 */
abstract class AbstractReportQuery implements ReportQueryInterface
{
    /** @var array<string, mixed> Applied filters (set via constructor). */
    protected array $filters;

    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    // ── Common filter helpers ──────────────────────────────────────

    protected function applyProjectFilter(Builder $query, string $column = 'project_id'): Builder
    {
        $value = $this->filters['project_id'] ?? null;

        return $value ? $query->where($column, $value) : $query;
    }

    protected function applyDistrictFilter(Builder $query, string $column = 'district_id'): Builder
    {
        $value = $this->filters['district_id'] ?? null;

        return $value ? $query->where($column, $value) : $query;
    }

    protected function applyUpazilaFilter(Builder $query, string $column = 'upazila_id'): Builder
    {
        $value = $this->filters['upazila_id'] ?? null;

        return $value ? $query->where($column, $value) : $query;
    }

    protected function applyStatusFilter(Builder $query, string $column = 'status'): Builder
    {
        $value = $this->filters['status'] ?? null;

        return $value ? $query->where($column, $value) : $query;
    }

    protected function applyDateRangeFilter(Builder $query, string $dateColumn): Builder
    {
        if ($this->filters['from_date'] ?? null) {
            $query->where($dateColumn, '>=', $this->filters['from_date']);
        }
        if ($this->filters['to_date'] ?? null) {
            $query->where($dateColumn, '<=', $this->filters['to_date']);
        }

        return $query;
    }

    /**
     * Apply geo filters via an eager-loaded `affectedPerson` relation.
     * Use when the primary query is not on `affected_person` directly.
     */
    protected function applyGeoFiltersToEp(Builder $query): Builder
    {
        return $query->whereHas('affectedPerson', function (Builder $ep): void {
            $this->applyProjectFilter($ep);
            $this->applyDistrictFilter($ep);
            $this->applyUpazilaFilter($ep);
        });
    }

    /**
     * Return the column definitions for export.
     *
     * @return array<int, array{key: string, label: string, type?: string}>
     */
    abstract protected function columns(): array;

    /**
     * Paginate the rows for screen display.
     * Returns null when pagination is not requested (export mode).
     */
    protected function perPage(): ?int
    {
        $raw = $this->filters['per_page'] ?? null;

        return $raw ? min((int) $raw, 100) : null;
    }
}
