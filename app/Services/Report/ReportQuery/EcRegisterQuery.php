<?php

namespace App\Services\Report\ReportQuery;

use App\Models\Ec;

/**
 * R06 — EC Register.
 *
 * Full list of Estimate of Compensation forms with affected person
 * details and aggregate financial summaries.
 *
 * Source: ec → affected_person
 */
class EcRegisterQuery extends AbstractReportQuery
{
    public function execute(): array
    {
        $query = Ec::query()
            ->select([
                'ec.ec_id',
                'ec.ec_number',
                'affected_person.ep_id',
                'affected_person.name AS ep_name',
                'ec.assessment_date',
                'ec.total_assessed_loss',
                'ec.total_compensation',
                'ec.status',
            ])
            ->join('affected_person', 'affected_person.affected_person_id', '=', 'ec.affected_person_id');

        $this->applyProjectFilter($query, 'affected_person.project_id');
        $this->applyDistrictFilter($query, 'affected_person.district_id');
        $this->applyUpazilaFilter($query, 'affected_person.upazila_id');
        $this->applyStatusFilter($query, 'ec.status');

        $query->orderBy('ec.ec_id');

        $rows = $query->get()->toArray();

        // Cast money columns to string for JSON serialization.
        foreach ($rows as &$row) {
            $row['total_assessed_loss'] = (string) $row['total_assessed_loss'];
            $row['total_compensation'] = (string) $row['total_compensation'];
        }
        unset($row);

        $totalLoss = array_sum(array_map(fn ($r) => (float) $r['total_assessed_loss'], $rows));
        $totalComp = array_sum(array_map(fn ($r) => (float) $r['total_compensation'], $rows));

        return [
            'title' => 'EC Register',
            'columns' => $this->columns(),
            'rows' => $rows,
            'summary' => [
                'total_ec' => count($rows),
                'total_assessed' => number_format($totalLoss, 2, '.', ''),
                'total_compensation' => number_format($totalComp, 2, '.', ''),
            ],
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'ec_id',              'label' => 'EC ID',              'type' => 'integer'],
            ['key' => 'ec_number',           'label' => 'EC Number',          'type' => 'string'],
            ['key' => 'ep_id',               'label' => 'EP ID',              'type' => 'string'],
            ['key' => 'ep_name',             'label' => 'EP Name',            'type' => 'string'],
            ['key' => 'assessment_date',     'label' => 'Assessment Date',    'type' => 'date'],
            ['key' => 'total_assessed_loss', 'label' => 'Total Assessed',     'type' => 'money'],
            ['key' => 'total_compensation',  'label' => 'Total Compensation', 'type' => 'money'],
            ['key' => 'status',              'label' => 'Status',             'type' => 'string'],
        ];
    }
}
