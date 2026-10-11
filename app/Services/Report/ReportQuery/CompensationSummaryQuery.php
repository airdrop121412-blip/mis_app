<?php

namespace App\Services\Report\ReportQuery;

use App\Models\Compensation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * R11 — Compensation Summary.
 *
 * Aggregated compensation amounts per damage category across all ECs.
 * Provides a loss-distribution view of approved compensation.
 *
 * Source: compensation → ec → affected_person
 */
class CompensationSummaryQuery extends AbstractReportQuery
{
    public function execute(): array
    {
        $query = Compensation::query()
            ->select([
                'compensation.category',
                DB::raw('COUNT(*) AS compensation_count'),
                DB::raw('SUM(compensation.assessed_amount) AS total_assessed'),
                DB::raw('SUM(compensation.approved_amount) AS total_approved'),
            ])
            ->join('ec', 'ec.ec_id', '=', 'compensation.ec_id');

        $this->applyGeoFilter($query);
        $this->applyStatusFilter($query, 'compensation.status');

        $query->groupBy('compensation.category')
            ->orderBy('total_approved', 'desc');

        $rows = $query->get()->toArray();

        $totalApproved = array_sum(array_map(fn ($r) => (float) $r['total_approved'], $rows));

        foreach ($rows as &$row) {
            $row['total_assessed'] = (string) $row['total_assessed'];
            $row['total_approved'] = (string) $row['total_approved'];
            $row['percentage'] = $totalApproved > 0
                ? round(((float) $row['total_approved'] / $totalApproved) * 100, 2)
                : '0.00';
        }
        unset($row);

        return [
            'title' => 'Compensation Summary',
            'columns' => $this->columns(),
            'rows' => $rows,
            'summary' => [
                'total_compensations' => (int) collect($rows)->sum('compensation_count'),
                'total_approved' => number_format($totalApproved, 2, '.', ''),
            ],
        ];
    }

    private function applyGeoFilter(Builder $query): void
    {
        $needsApJoin = ! empty($this->filters['project_id'])
            || ! empty($this->filters['district_id'])
            || ! empty($this->filters['upazila_id']);

        if ($needsApJoin) {
            $query->join('affected_person', 'affected_person.affected_person_id', '=', 'ec.affected_person_id');
        }

        if (! empty($this->filters['project_id'])) {
            $query->where('affected_person.project_id', $this->filters['project_id']);
        }
        if (! empty($this->filters['district_id'])) {
            $query->where('affected_person.district_id', $this->filters['district_id']);
        }
        if (! empty($this->filters['upazila_id'])) {
            $query->where('affected_person.upazila_id', $this->filters['upazila_id']);
        }
    }

    protected function columns(): array
    {
        return [
            ['key' => 'category',            'label' => 'Category',            'type' => 'string'],
            ['key' => 'compensation_count',   'label' => 'Compensation Count',  'type' => 'integer'],
            ['key' => 'total_assessed',       'label' => 'Total Assessed',      'type' => 'money'],
            ['key' => 'total_approved',       'label' => 'Total Approved',      'type' => 'money'],
            ['key' => 'percentage',           'label' => 'Percentage',          'type' => 'percentage'],
        ];
    }
}
