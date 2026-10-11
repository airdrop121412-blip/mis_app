<?php

namespace App\Services\Report\ReportQuery;

use App\Models\Epicad;
use Illuminate\Support\Facades\DB;

/**
 * R23 — EPICAD Print Status.
 *
 * Distribution of EPICAD print statuses (NOT_PRINTED, PRINTED, REPRINTED).
 * Shows the EP card printing pipeline with optional geographic filtering.
 *
 * Source: epicad → affected_person
 */
class EpicadPrintStatusQuery extends AbstractReportQuery
{
    public function execute(): array
    {
        $query = Epicad::query()
            ->select([
                'epicad.print_status',
                DB::raw('COUNT(*) AS print_count'),
            ])
            ->join('affected_person', 'affected_person.affected_person_id', '=', 'epicad.affected_person_id');

        $this->applyProjectFilter($query, 'affected_person.project_id');
        $this->applyDistrictFilter($query, 'affected_person.district_id');
        $this->applyUpazilaFilter($query, 'affected_person.upazila_id');

        $query->groupBy('epicad.print_status')
            ->orderBy('epicad.print_status');

        $rows = $query->get()->toArray();

        $totalCount = array_sum(array_column($rows, 'print_count'));

        foreach ($rows as &$row) {
            $row['print_count'] = (int) $row['print_count'];
            $row['percentage'] = $totalCount > 0
                ? round(($row['print_count'] / $totalCount) * 100, 2)
                : '0.00';
        }
        unset($row);

        return [
            'title' => 'EPICAD Print Status',
            'columns' => $this->columns(),
            'rows' => $rows,
            'summary' => [
                'total_epicads' => $totalCount,
            ],
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'print_status', 'label' => 'Print Status', 'type' => 'string'],
            ['key' => 'print_count',  'label' => 'Print Count',  'type' => 'integer'],
            ['key' => 'percentage',   'label' => 'Percentage',   'type' => 'percentage'],
        ];
    }
}
