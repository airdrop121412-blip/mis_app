<?php

namespace App\Services\Report\ReportQuery;

use App\Models\Ec;
use Illuminate\Support\Facades\DB;

/**
 * R07 — EC Status Summary.
 *
 * Counts of ECs grouped by workflow status. Provides pipeline
 * visibility for the compensation approval workflow.
 *
 * Source: ec (aggregated)
 */
class EcStatusSummaryQuery extends AbstractReportQuery
{
    public function execute(): array
    {
        $query = Ec::query()
            ->select([
                'ec.status',
                DB::raw('COUNT(*) AS ec_count'),
                DB::raw('SUM(ec.total_assessed_loss) AS total_assessed'),
                DB::raw('SUM(ec.total_compensation) AS total_compensation'),
            ])
            ->groupBy('ec.status')
            ->orderBy('ec.status');

        // Apply geographic filters via affected_person join.
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

        $rows = $query->get()->toArray();

        $total = array_sum(array_map(fn ($r) => (int) $r['ec_count'], $rows));

        foreach ($rows as &$row) {
            $row['percentage'] = $total > 0
                ? round(((int) $row['ec_count'] / $total) * 100, 2)
                : '0.00';
            $row['total_assessed'] = (string) $row['total_assessed'];
            $row['total_compensation'] = (string) $row['total_compensation'];
        }
        unset($row);

        return [
            'title' => 'EC Status Summary',
            'columns' => $this->columns(),
            'rows' => $rows,
            'summary' => ['total_ec' => $total],
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'status',             'label' => 'Status',             'type' => 'string'],
            ['key' => 'ec_count',           'label' => 'EC Count',           'type' => 'integer'],
            ['key' => 'percentage',         'label' => 'Percentage',         'type' => 'percentage'],
            ['key' => 'total_assessed',     'label' => 'Total Assessed',     'type' => 'money'],
            ['key' => 'total_compensation', 'label' => 'Total Compensation', 'type' => 'money'],
        ];
    }
}
