<?php

namespace App\Services\Report\ReportQuery;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * R22 — Workflow Status Report.
 *
 * Multi-entity pipeline view showing status distributions across
 * all major entity types (EP, EC, Compensation, Voucher, Allowance).
 * Each entity type is a row-group showing counts per status.
 *
 * Source: UNION ALL of per-entity status counts with geo filters.
 */
class WorkflowStatusQuery extends AbstractReportQuery
{
    public function execute(): array
    {
        // Build individual status queries for each entity type.
        // Each returns {entity_type, status, count}.
        $entityQueries = [];

        // 1. Affected Person (EP)
        $epQuery = DB::table('affected_person')
            ->select(
                DB::raw("'EP' AS entity_type"),
                'status',
                DB::raw('COUNT(*) AS count'),
            )
            ->groupBy('status');
        $this->applyGeoFiltersToEpQuery($epQuery);
        $entityQueries[] = $epQuery;

        // 2. EC
        $ecQuery = DB::table('ec')
            ->select(
                DB::raw("'EC' AS entity_type"),
                'ec.status',
                DB::raw('COUNT(*) AS count'),
            )
            ->join('affected_person', 'affected_person.affected_person_id', '=', 'ec.affected_person_id')
            ->groupBy('ec.status');
        $this->applyGeoFiltersToEpQuery($ecQuery);
        $entityQueries[] = $ecQuery;

        // 3. Compensation
        $compQuery = DB::table('compensation')
            ->select(
                DB::raw("'COMPENSATION' AS entity_type"),
                'compensation.status',
                DB::raw('COUNT(*) AS count'),
            )
            ->join('ec', 'ec.ec_id', '=', 'compensation.ec_id')
            ->join('affected_person', 'affected_person.affected_person_id', '=', 'ec.affected_person_id')
            ->groupBy('compensation.status');
        $this->applyGeoFiltersToEpQuery($compQuery);
        $entityQueries[] = $compQuery;

        // 4. Voucher
        $vchQuery = DB::table('voucher')
            ->select(
                DB::raw("'VOUCHER' AS entity_type"),
                'voucher.payment_status AS status',
                DB::raw('COUNT(*) AS count'),
            )
            ->join('ec', 'ec.ec_id', '=', 'voucher.ec_id')
            ->join('affected_person', 'affected_person.affected_person_id', '=', 'ec.affected_person_id')
            ->groupBy('voucher.payment_status');
        $this->applyGeoFiltersToEpQuery($vchQuery);
        $entityQueries[] = $vchQuery;

        // 5. Allowance
        $allowQuery = DB::table('allowance')
            ->select(
                DB::raw("'ALLOWANCE' AS entity_type"),
                'allowance.status',
                DB::raw('COUNT(*) AS count'),
            )
            ->join('affected_person', 'affected_person.affected_person_id', '=', 'allowance.affected_person_id')
            ->groupBy('allowance.status');
        $this->applyGeoFiltersToEpQuery($allowQuery);
        $entityQueries[] = $allowQuery;

        // Union all entity queries.
        $unionQuery = $entityQueries[0];
        for ($i = 1; $i < count($entityQueries); $i++) {
            $unionQuery->unionAll($entityQueries[$i]);
        }

        $rows = DB::select("({$unionQuery->toSql()})", $unionQuery->getBindings());

        // Sort by entity_type then status.
        usort($rows, function ($a, $b) {
            $cmp = strcmp($a->entity_type, $b->entity_type);

            return $cmp !== 0 ? $cmp : strcmp($a->status, $b->status);
        });

        // Convert to arrays and compute per-entity totals.
        $totalByEntity = [];
        $resultRows = [];
        foreach ($rows as $row) {
            $r = (array) $row;
            $resultRows[] = $r;
            $totalByEntity[$r['entity_type']] = ($totalByEntity[$r['entity_type']] ?? 0) + (int) $r['count'];
        }

        return [
            'title' => 'Workflow Status Report',
            'columns' => $this->columns(),
            'rows' => $resultRows,
            'summary' => [
                'entity_totals' => $totalByEntity,
            ],
        ];
    }

    /**
     * Apply geo filters via affected_person join (for non-EP tables).
     */
    private function applyGeoFiltersToEpQuery(Builder $query): void
    {
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
            ['key' => 'entity_type', 'label' => 'Entity Type', 'type' => 'string'],
            ['key' => 'status',      'label' => 'Status',       'type' => 'string'],
            ['key' => 'count',       'label' => 'Count',        'type' => 'integer'],
        ];
    }
}
