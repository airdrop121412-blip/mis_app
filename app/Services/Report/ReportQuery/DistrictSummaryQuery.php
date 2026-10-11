<?php

namespace App\Services\Report\ReportQuery;

use Illuminate\Support\Facades\DB;

/**
 * R19 — District Summary.
 *
 * Aggregated EP, EC, compensation, and allowance statistics per district.
 * Provides a geographic overview of survey and compensation coverage.
 *
 * FIX (Audit 2025-07): Replaced flat LEFT JOINs + SUM(DISTINCT) with
 * pre-aggregated subqueries to prevent row-multiplication and value-dedup
 * bugs. Each entity type is aggregated in its own derived table, then
 * LEFT JOINed to the district dimension — so a SUM() over the derived
 * table is always correct.
 */
class DistrictSummaryQuery extends AbstractReportQuery
{
    public function execute(): array
    {
        $projectId = $this->filters['project_id'] ?? null;

        // ── Sub-query 1: EP counts per district ──────────────
        $epStats = DB::table('affected_person')
            ->select('district_id', DB::raw('COUNT(DISTINCT affected_person_id) AS ep_count'));

        if ($projectId) {
            $epStats->where('project_id', $projectId);
        }
        $epStats->groupBy('district_id');

        // ── Sub-query 2: EC totals per district ──────────────
        $ecStats = DB::table('ec')
            ->join('affected_person', 'ec.affected_person_id', '=', 'affected_person.affected_person_id')
            ->select(
                'affected_person.district_id',
                DB::raw('COUNT(DISTINCT ec.ec_id) AS ec_count'),
                DB::raw('COALESCE(SUM(ec.total_assessed_loss), 0) AS total_assessed'),
                DB::raw('COALESCE(SUM(ec.total_compensation), 0) AS total_compensation')
            );
        if ($projectId) {
            $ecStats->where('affected_person.project_id', $projectId);
        }
        $ecStats->groupBy('affected_person.district_id');

        // ── Sub-query 3: Allowance totals per district ───────
        $allowanceStats = DB::table('allowance')
            ->join('affected_person', 'allowance.affected_person_id', '=', 'affected_person.affected_person_id')
            ->select(
                'affected_person.district_id',
                DB::raw('COUNT(DISTINCT allowance.allowance_id) AS allowance_count'),
                DB::raw('COALESCE(SUM(allowance.grant_amount), 0) AS total_grant')
            );
        if ($projectId) {
            $allowanceStats->where('affected_person.project_id', $projectId);
        }
        $allowanceStats->groupBy('affected_person.district_id');

        // ── Main query: district dimension + LEFT JOIN sub-queries ──
        $query = DB::table('district')
            ->joinSub($epStats, 'ep_stats', 'district.district_id', '=', 'ep_stats.district_id')
            ->leftJoinSub($ecStats, 'ec_stats', 'district.district_id', '=', 'ec_stats.district_id')
            ->leftJoinSub($allowanceStats, 'al_stats', 'district.district_id', '=', 'al_stats.district_id')
            ->select(
                'district.district_id',
                'district.name AS district_name',
                'district.code AS district_code',
                'ep_stats.ep_count',
                DB::raw('COALESCE(ec_stats.ec_count, 0) AS ec_count'),
                DB::raw('COALESCE(ec_stats.total_assessed, 0) AS total_assessed'),
                DB::raw('COALESCE(ec_stats.total_compensation, 0) AS total_compensation'),
                DB::raw('COALESCE(al_stats.allowance_count, 0) AS allowance_count'),
                DB::raw('COALESCE(al_stats.total_grant, 0) AS total_grant')
            );

        $districtId = $this->filters['district_id'] ?? null;
        if ($districtId) {
            $query->where('district.district_id', $districtId);
        }

        $rows = $query->orderBy('ep_count', 'desc')->get()->toArray();

        foreach ($rows as &$row) {
            $row = (array) $row;
            $row['total_assessed'] = (string) $row['total_assessed'];
            $row['total_compensation'] = (string) $row['total_compensation'];
            $row['total_grant'] = (string) $row['total_grant'];
        }
        unset($row);

        $totalEp = array_sum(array_column($rows, 'ep_count'));

        return [
            'title' => 'District Summary',
            'columns' => $this->columns(),
            'rows' => $rows,
            'summary' => [
                'total_districts' => count($rows),
                'total_eps' => $totalEp,
            ],
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'district_id',        'label' => 'District ID',        'type' => 'integer'],
            ['key' => 'district_name',       'label' => 'District',           'type' => 'string'],
            ['key' => 'district_code',       'label' => 'District Code',      'type' => 'string'],
            ['key' => 'ep_count',            'label' => 'EP Count',           'type' => 'integer'],
            ['key' => 'ec_count',            'label' => 'EC Count',           'type' => 'integer'],
            ['key' => 'total_assessed',      'label' => 'Total Assessed',     'type' => 'money'],
            ['key' => 'total_compensation',  'label' => 'Total Compensation', 'type' => 'money'],
            ['key' => 'allowance_count',     'label' => 'Allowance Count',    'type' => 'integer'],
            ['key' => 'total_grant',         'label' => 'Total Grant',        'type' => 'money'],
        ];
    }
}
