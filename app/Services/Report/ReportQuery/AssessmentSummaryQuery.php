<?php

namespace App\Services\Report\ReportQuery;

use App\Models\EcLineItem;
use Illuminate\Support\Facades\DB;

/**
 * R08 — Assessment Summary by Category.
 *
 * Aggregated assessment amounts per compensation category (LAND, CROP,
 * INFRASTRUCTURE, etc.) across all ECs. Provides a loss-distribution view.
 *
 * Source: ec_line_item → ec → affected_person
 */
class AssessmentSummaryQuery extends AbstractReportQuery
{
    public function execute(): array
    {
        $query = EcLineItem::query()
            ->select([
                'ec_line_item.category',
                DB::raw('COUNT(DISTINCT ec.ec_id) AS ec_count'),
                DB::raw('COUNT(DISTINCT ec.affected_person_id) AS ep_count'),
                DB::raw('SUM(ec_line_item.assessed_amount) AS total_assessed'),
                DB::raw('COUNT(*) AS line_count'),
            ])
            ->join('ec', 'ec.ec_id', '=', 'ec_line_item.ec_id');

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

        // Apply EC status filter.
        if (! empty($this->filters['status'])) {
            $query->where('ec.status', $this->filters['status']);
        }

        $query->groupBy('ec_line_item.category')
            ->orderBy('total_assessed', 'desc');

        $rows = $query->get()->toArray();

        $totalAssessed = array_sum(array_map(fn ($r) => (float) $r['total_assessed'], $rows));
        $totalEps = (int) collect($rows)->sum('ep_count');

        foreach ($rows as &$row) {
            $row['total_assessed'] = (string) $row['total_assessed'];
            $row['percentage'] = $totalAssessed > 0
                ? round(((float) $row['total_assessed'] / $totalAssessed) * 100, 2)
                : '0.00';
        }
        unset($row);

        return [
            'title' => 'Assessment Summary by Category',
            'columns' => $this->columns(),
            'rows' => $rows,
            'summary' => [
                'total_assessed' => number_format($totalAssessed, 2, '.', ''),
                'total_categories' => count($rows),
            ],
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'category',      'label' => 'Category',      'type' => 'string'],
            ['key' => 'ec_count',      'label' => 'EC Count',      'type' => 'integer'],
            ['key' => 'ep_count',      'label' => 'EP Count',      'type' => 'integer'],
            ['key' => 'total_assessed', 'label' => 'Total Assessed', 'type' => 'money'],
            ['key' => 'line_count',    'label' => 'Line Items',     'type' => 'integer'],
            ['key' => 'percentage',    'label' => 'Percentage',     'type' => 'percentage'],
        ];
    }
}
