<?php

namespace App\Services\Report\ReportQuery;

use App\Models\AffectedPerson;
use Illuminate\Support\Facades\DB;

/**
 * SWTPP-compatible EP compensation register.
 *
 * This is the normalized MIS equivalent of Access's "EP Information" report.
 * Component totals are calculated from assessments, never copied into a second
 * financial ledger. `legacy_dc_compensation` is retained only for import
 * reconciliation against the historical Access total.
 */
class SwtppEpCompensationQuery extends AbstractReportQuery
{
    public function execute(): array
    {
        $land = DB::table('land_assessment')
            ->join('land', 'land.land_id', '=', 'land_assessment.land_id')
            ->selectRaw('land.affected_person_id, SUM(land_assessment.assessed_loss) AS total')
            ->groupBy('land.affected_person_id');
        $tree = DB::table('tree_assessment')
            ->join('tree', 'tree.tree_id', '=', 'tree_assessment.tree_id')
            ->selectRaw('tree.affected_person_id, SUM(tree_assessment.assessed_loss) AS total')
            ->groupBy('tree.affected_person_id');
        $structure = DB::table('infrastructure_assessment')
            ->join('infrastructure', 'infrastructure.infrastructure_id', '=', 'infrastructure_assessment.infrastructure_id')
            ->selectRaw('infrastructure.affected_person_id, SUM(infrastructure_assessment.assessed_loss) AS total')
            ->groupBy('infrastructure.affected_person_id');
        $worker = DB::table('worker_assessment')
            ->join('worker', 'worker.worker_id', '=', 'worker_assessment.worker_id')
            ->selectRaw('worker.affected_person_id, SUM(worker_assessment.loss_amount) AS total')
            ->groupBy('worker.affected_person_id');

        $query = AffectedPerson::query()
            ->select([
                'affected_person.ep_id', 'affected_person.name', 'affected_person.father_name',
                'affected_person.mother_name', 'affected_person.total_family_member',
                'affected_person.male_member', 'affected_person.female_member',
                'affected_person.total_tenant', 'affected_person.stamp_duty',
                'affected_person.dc_compensation AS legacy_dc_compensation',
                'affected_person.cheque_no', 'affected_person.cheque_date', 'affected_person.status',
                DB::raw('COALESCE(land_total.total, 0) AS land_compensation'),
                DB::raw('COALESCE(tree_total.total, 0) AS tree_compensation'),
                DB::raw('COALESCE(structure_total.total, 0) AS structure_compensation'),
                DB::raw('COALESCE(worker_total.total, 0) AS wage_compensation'),
            ])
            ->leftJoinSub($land, 'land_total', 'land_total.affected_person_id', '=', 'affected_person.affected_person_id')
            ->leftJoinSub($tree, 'tree_total', 'tree_total.affected_person_id', '=', 'affected_person.affected_person_id')
            ->leftJoinSub($structure, 'structure_total', 'structure_total.affected_person_id', '=', 'affected_person.affected_person_id')
            ->leftJoinSub($worker, 'worker_total', 'worker_total.affected_person_id', '=', 'affected_person.affected_person_id');

        $this->applyProjectFilter($query, 'affected_person.project_id');
        $this->applyDistrictFilter($query, 'affected_person.district_id');
        $this->applyUpazilaFilter($query, 'affected_person.upazila_id');
        $this->applyStatusFilter($query, 'affected_person.status');

        $rows = $query->orderBy('affected_person.ep_id')->get()->map(function (AffectedPerson $model): array {
            $row = $model->getAttributes();
            $total = (float) $row['land_compensation'] + (float) $row['tree_compensation']
                + (float) $row['structure_compensation'] + (float) $row['wage_compensation']
                + (float) ($row['stamp_duty'] ?? 0);
            foreach (['land_compensation', 'tree_compensation', 'structure_compensation', 'wage_compensation', 'stamp_duty', 'legacy_dc_compensation'] as $key) {
                $row[$key] = number_format((float) ($row[$key] ?? 0), 2, '.', '');
            }
            $row['calculated_dc_compensation'] = number_format($total, 2, '.', '');
            $row['reconciliation_difference'] = number_format($total - (float) ($row['legacy_dc_compensation'] ?? 0), 2, '.', '');

            return $row;
        })->all();

        return [
            'title' => 'SWTPP EP Compensation Register',
            'columns' => $this->columns(),
            'rows' => $rows,
            'summary' => [
                'total_eps' => count($rows),
                'total_calculated_compensation' => number_format(array_sum(array_map(fn ($row) => (float) $row['calculated_dc_compensation'], $rows)), 2, '.', ''),
            ],
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'ep_id', 'label' => 'EP ID', 'type' => 'string'],
            ['key' => 'name', 'label' => 'EP Name', 'type' => 'string'],
            ['key' => 'father_name', 'label' => 'Father Name', 'type' => 'string'],
            ['key' => 'mother_name', 'label' => 'Mother Name', 'type' => 'string'],
            ['key' => 'total_family_member', 'label' => 'Total Family', 'type' => 'integer'],
            ['key' => 'male_member', 'label' => 'Male', 'type' => 'integer'],
            ['key' => 'female_member', 'label' => 'Female', 'type' => 'integer'],
            ['key' => 'total_tenant', 'label' => 'Total Tenant', 'type' => 'integer'],
            ['key' => 'land_compensation', 'label' => 'Land Compensation', 'type' => 'money'],
            ['key' => 'tree_compensation', 'label' => 'Tree Compensation', 'type' => 'money'],
            ['key' => 'structure_compensation', 'label' => 'Structure Compensation', 'type' => 'money'],
            ['key' => 'wage_compensation', 'label' => 'Wage Compensation', 'type' => 'money'],
            ['key' => 'stamp_duty', 'label' => 'Stamp Duty', 'type' => 'money'],
            ['key' => 'calculated_dc_compensation', 'label' => 'Calculated DC Compensation', 'type' => 'money'],
            ['key' => 'legacy_dc_compensation', 'label' => 'Legacy DC Compensation', 'type' => 'money'],
            ['key' => 'reconciliation_difference', 'label' => 'Difference', 'type' => 'money'],
            ['key' => 'cheque_no', 'label' => 'Cheque No', 'type' => 'string'],
            ['key' => 'cheque_date', 'label' => 'Cheque Date', 'type' => 'date'],
            ['key' => 'status', 'label' => 'MIS Status', 'type' => 'string'],
        ];
    }
}
