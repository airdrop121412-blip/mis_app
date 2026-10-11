<?php

namespace App\Services\Report\ReportQuery;

use App\Models\AffectedPerson;
use Illuminate\Support\Facades\DB;

/**
 * R02 — EP Status Summary.
 *
 * Counts of affected persons grouped by status. Provides a pipeline
 * visibility snapshot: how many EPs are at each workflow stage.
 *
 * Source: affected_person (aggregated)
 */
class EpStatusSummaryQuery extends AbstractReportQuery
{
    public function execute(): array
    {
        $query = AffectedPerson::query()
            ->select([
                'status',
                DB::raw('COUNT(*) AS ep_count'),
            ])
            ->groupBy('status')
            ->orderBy('status');

        $this->applyProjectFilter($query);
        $this->applyDistrictFilter($query);
        $this->applyUpazilaFilter($query);

        $rows = $query->get()->toArray();

        $total = array_sum(array_map(fn ($r) => (int) $r['ep_count'], $rows));

        // Add percentage to each row.
        foreach ($rows as &$row) {
            $row['percentage'] = $total > 0
                ? round(((int) $row['ep_count'] / $total) * 100, 2)
                : '0.00';
        }
        unset($row);

        return [
            'title' => 'EP Status Summary',
            'columns' => $this->columns(),
            'rows' => $rows,
            'summary' => ['total_ep' => $total],
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'status',    'label' => 'Status',    'type' => 'string'],
            ['key' => 'ep_count',  'label' => 'EP Count',  'type' => 'integer'],
            ['key' => 'percentage', 'label' => 'Percentage', 'type' => 'percentage'],
        ];
    }
}
