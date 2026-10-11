<?php

namespace App\Services\Report\ReportQuery;

use App\Models\Allowance;

/**
 * R12 — Allowance List.
 *
 * Full listing of all allowance records with affected person details
 * and vulnerability rule information.
 *
 * Source: allowance → affected_person, vulnerability_rule
 */
class AllowanceListQuery extends AbstractReportQuery
{
    public function execute(): array
    {
        $query = Allowance::query()
            ->select([
                'allowance.allowance_id',
                'affected_person.ep_id',
                'affected_person.name AS ep_name',
                'vulnerability_rule.rule_code',
                'vulnerability_rule.rule_type',
                'allowance.is_eligible',
                'allowance.computed_annual_income',
                'allowance.grant_amount',
                'allowance.status',
            ])
            ->join('affected_person', 'affected_person.affected_person_id', '=', 'allowance.affected_person_id')
            ->join('vulnerability_rule', 'vulnerability_rule.vulnerability_rule_id', '=', 'allowance.vulnerability_rule_id');

        $this->applyProjectFilter($query, 'affected_person.project_id');
        $this->applyDistrictFilter($query, 'affected_person.district_id');
        $this->applyUpazilaFilter($query, 'affected_person.upazila_id');
        $this->applyStatusFilter($query, 'allowance.status');

        $query->orderBy('allowance.allowance_id');

        $rows = $query->get()->toArray();

        foreach ($rows as &$row) {
            $row['computed_annual_income'] = $row['computed_annual_income'] !== null
                ? (string) $row['computed_annual_income']
                : null;
            $row['grant_amount'] = (string) $row['grant_amount'];
        }
        unset($row);

        return [
            'title' => 'Allowance List',
            'columns' => $this->columns(),
            'rows' => $rows,
            'summary' => ['total_count' => count($rows)],
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'allowance_id',           'label' => 'Allowance ID',           'type' => 'integer'],
            ['key' => 'ep_id',                   'label' => 'EP ID',                  'type' => 'string'],
            ['key' => 'ep_name',                 'label' => 'EP Name',                'type' => 'string'],
            ['key' => 'rule_code',               'label' => 'Rule Code',              'type' => 'string'],
            ['key' => 'rule_type',               'label' => 'Rule Type',              'type' => 'string'],
            ['key' => 'is_eligible',             'label' => 'Eligible',               'type' => 'boolean'],
            ['key' => 'computed_annual_income',   'label' => 'Computed Annual Income', 'type' => 'money'],
            ['key' => 'grant_amount',             'label' => 'Grant Amount',           'type' => 'money'],
            ['key' => 'status',                   'label' => 'Status',                 'type' => 'string'],
        ];
    }
}
