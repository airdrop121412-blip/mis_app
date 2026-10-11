<?php

namespace App\Services\Report\ReportQuery;

use Illuminate\Support\Facades\DB;

/**
 * R21 — Project Summary.
 *
 * Aggregated EP, EC, compensation, allowance, voucher, and payment statistics
 * per project. Provides an overview of project-level performance.
 *
 * FIX (Audit 2025-07): Replaced flat LEFT JOINs + SUM(DISTINCT) with
 * pre-aggregated subqueries to prevent row-multiplication and value-dedup
 * bugs. Each entity type is aggregated in its own derived table, then
 * LEFT JOINed to the project dimension — so a SUM() over the derived
 * table is always correct.
 */
class ProjectSummaryQuery extends AbstractReportQuery
{
    public function execute(): array
    {
        $projectId = $this->filters['project_id'] ?? null;

        // ── Sub-query 1: EP counts per project ──────────────
        $epStats = DB::table('affected_person')
            ->select('project_id', DB::raw('COUNT(DISTINCT affected_person_id) AS ep_count'));

        if ($projectId) {
            $epStats->where('project_id', $projectId);
        }
        $epStats->groupBy('project_id');

        // ── Sub-query 2: EC totals per project ──────────────
        $ecStats = DB::table('ec')
            ->join('affected_person', 'ec.affected_person_id', '=', 'affected_person.affected_person_id')
            ->select(
                'affected_person.project_id',
                DB::raw('COUNT(DISTINCT ec.ec_id) AS ec_count'),
                DB::raw('COALESCE(SUM(ec.total_assessed_loss), 0) AS total_assessed'),
                DB::raw('COALESCE(SUM(ec.total_compensation), 0) AS total_compensation')
            );
        if ($projectId) {
            $ecStats->where('affected_person.project_id', $projectId);
        }
        $ecStats->groupBy('affected_person.project_id');

        // ── Sub-query 3: Allowance totals per project ───────
        $allowanceStats = DB::table('allowance')
            ->join('affected_person', 'allowance.affected_person_id', '=', 'affected_person.affected_person_id')
            ->select(
                'affected_person.project_id',
                DB::raw('COUNT(DISTINCT allowance.allowance_id) AS allowance_count'),
                DB::raw('COALESCE(SUM(allowance.grant_amount), 0) AS total_grant')
            );
        if ($projectId) {
            $allowanceStats->where('affected_person.project_id', $projectId);
        }
        $allowanceStats->groupBy('affected_person.project_id');

        // ── Sub-query 4: Voucher counts per project ─────────
        $voucherStats = DB::table('voucher')
            ->join('ec', 'voucher.ec_id', '=', 'ec.ec_id')
            ->join('affected_person', 'ec.affected_person_id', '=', 'affected_person.affected_person_id')
            ->select(
                'affected_person.project_id',
                DB::raw('COUNT(DISTINCT voucher.voucher_id) AS voucher_count')
            );
        if ($projectId) {
            $voucherStats->where('affected_person.project_id', $projectId);
        }
        $voucherStats->groupBy('affected_person.project_id');

        // ── Sub-query 5: Payment totals per project ─────────
        $paymentStats = DB::table('payment')
            ->join('voucher', 'payment.voucher_id', '=', 'voucher.voucher_id')
            ->join('ec', 'voucher.ec_id', '=', 'ec.ec_id')
            ->join('affected_person', 'ec.affected_person_id', '=', 'affected_person.affected_person_id')
            ->select(
                'affected_person.project_id',
                DB::raw('COALESCE(SUM(payment.amount), 0) AS total_paid')
            );
        if ($projectId) {
            $paymentStats->where('affected_person.project_id', $projectId);
        }
        $paymentStats->groupBy('affected_person.project_id');

        // ── Main query: project dimension + LEFT JOIN sub-queries ──
        $query = DB::table('project')
            ->joinSub($epStats, 'ep_stats', 'project.project_id', '=', 'ep_stats.project_id')
            ->leftJoinSub($ecStats, 'ec_stats', 'project.project_id', '=', 'ec_stats.project_id')
            ->leftJoinSub($allowanceStats, 'al_stats', 'project.project_id', '=', 'al_stats.project_id')
            ->leftJoinSub($voucherStats, 'vch_stats', 'project.project_id', '=', 'vch_stats.project_id')
            ->leftJoinSub($paymentStats, 'pay_stats', 'project.project_id', '=', 'pay_stats.project_id')
            ->select(
                'project.project_id',
                'project.project_code',
                'project.name AS project_name',
                'ep_stats.ep_count',
                DB::raw('COALESCE(ec_stats.ec_count, 0) AS ec_count'),
                DB::raw('COALESCE(ec_stats.total_assessed, 0) AS total_assessed'),
                DB::raw('COALESCE(ec_stats.total_compensation, 0) AS total_compensation'),
                DB::raw('COALESCE(al_stats.allowance_count, 0) AS allowance_count'),
                DB::raw('COALESCE(al_stats.total_grant, 0) AS total_grant'),
                DB::raw('COALESCE(vch_stats.voucher_count, 0) AS voucher_count'),
                DB::raw('COALESCE(pay_stats.total_paid, 0) AS total_paid')
            );

        if ($projectId) {
            $query->where('project.project_id', $projectId);
        }

        $rows = $query->orderBy('ep_count', 'desc')->get()->toArray();

        foreach ($rows as &$row) {
            $row = (array) $row;
            $row['total_assessed'] = (string) $row['total_assessed'];
            $row['total_compensation'] = (string) $row['total_compensation'];
            $row['total_grant'] = (string) $row['total_grant'];
            $row['total_paid'] = (string) $row['total_paid'];
        }
        unset($row);

        $totalEp = array_sum(array_column($rows, 'ep_count'));

        return [
            'title' => 'Project Summary',
            'columns' => $this->columns(),
            'rows' => $rows,
            'summary' => [
                'total_projects' => count($rows),
                'total_eps' => $totalEp,
            ],
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'project_id',          'label' => 'Project ID',          'type' => 'integer'],
            ['key' => 'project_code',         'label' => 'Project Code',        'type' => 'string'],
            ['key' => 'project_name',         'label' => 'Project Name',        'type' => 'string'],
            ['key' => 'ep_count',             'label' => 'EP Count',            'type' => 'integer'],
            ['key' => 'ec_count',             'label' => 'EC Count',            'type' => 'integer'],
            ['key' => 'total_assessed',       'label' => 'Total Assessed',      'type' => 'money'],
            ['key' => 'total_compensation',   'label' => 'Total Compensation',  'type' => 'money'],
            ['key' => 'allowance_count',      'label' => 'Allowance Count',     'type' => 'integer'],
            ['key' => 'total_grant',          'label' => 'Total Grant',         'type' => 'money'],
            ['key' => 'voucher_count',        'label' => 'Voucher Count',       'type' => 'integer'],
            ['key' => 'total_paid',           'label' => 'Total Paid',          'type' => 'money'],
        ];
    }
}
