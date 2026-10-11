<?php

namespace App\Services\Report\ReportQuery;

use App\Models\Allowance;
use Illuminate\Support\Facades\DB;

/**
 * R13 — Allowance Summary.
 *
 * Aggregated allowance counts and grant amounts by status.
 * Provides pipeline visibility for the allowance workflow.
 *
 * Source: allowance → affected_person
 */
class AllowanceSummaryQuery extends AbstractReportQuery
{
    public function execute(): array
    {
        $query = Allowance::query()
            ->select([
                'allowance.status',
                DB::raw('COUNT(*) AS allowance_count'),
                DB::raw('SUM(allowance.grant_amount) AS total_grant'),
            ])
            ->join('affected_person', 'affected_person.affected_person_id', '=', 'allowance.affected_person_id');

        $this->applyProjectFilter($query, 'affected_person.project_id');
        $this->applyDistrictFilter($query, 'affected_person.district_id');
        $this->applyUpazilaFilter($query, 'affected_person.upazila_id');

        $query->groupBy('allowance.status')
            ->orderBy('allowance.status');

        $rows = $query->get()->toArray();

        $totalGrant = array_sum(array_map(fn ($r) => (float) $r['total_grant'], $rows));
        $totalCount = array_sum(array_map(fn ($r) => (int) $r['allowance_count'], $rows));

        foreach ($rows as &$row) {
            $row['total_grant'] = (string) $row['total_grant'];
            $row['percentage'] = $totalCount > 0
                ? round(((int) $row['allowance_count'] / $totalCount) * 100, 2)
                : '0.00';
        }
        unset($row);

        return [
            'title' => 'Allowance Summary',
            'columns' => $this->columns(),
            'rows' => $rows,
            'summary' => [
                'total_allowances' => $totalCount,
                'total_grant' => number_format($totalGrant, 2, '.', ''),
            ],
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'status',          'label' => 'Status',          'type' => 'string'],
            ['key' => 'allowance_count', 'label' => 'Allowance Count', 'type' => 'integer'],
            ['key' => 'total_grant',     'label' => 'Total Grant',     'type' => 'money'],
            ['key' => 'percentage',      'label' => 'Percentage',      'type' => 'percentage'],
        ];
    }
}
