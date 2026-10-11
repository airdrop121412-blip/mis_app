<?php

namespace App\Services\Report\ReportQuery;

use App\Models\AffectedPerson;
use Illuminate\Support\Facades\DB;

/**
 * R01 — EP Register.
 *
 * Full list of affected persons with personal, demographic, geographic,
 * and status information. This is the primary population listing report.
 *
 * Source: affected_person → gender, religion, occupation, district, upazila,
 *         land_status, residential_commercial_type
 */
class EpRegisterQuery extends AbstractReportQuery
{
    public function execute(): array
    {
        $query = AffectedPerson::query()
            ->select([
                'affected_person.ep_id',
                'affected_person.name',
                'affected_person.father_name',
                'affected_person.mother_name',
                'affected_person.nid_number',
                'affected_person.mobile',
                'affected_person.date_of_birth',
                'affected_person.monthly_income',
                'affected_person.monthly_expense',
                'affected_person.is_household_head',
                'affected_person.status',
                DB::raw('gender.name AS gender'),
                DB::raw('religion.name AS religion'),
                DB::raw('occupation.name AS occupation'),
                DB::raw('district.name AS district'),
                DB::raw('upazila.name AS upazila'),
                DB::raw('land_status.name AS land_status'),
                DB::raw('rc_type.name AS rc_type'),
            ])
            ->join('gender', 'gender.gender_id', '=', 'affected_person.gender_id')
            ->leftJoin('religion', 'religion.religion_id', '=', 'affected_person.religion_id')
            ->leftJoin('occupation', 'occupation.occupation_id', '=', 'affected_person.occupation_id')
            ->join('district', 'district.district_id', '=', 'affected_person.district_id')
            ->join('upazila', 'upazila.upazila_id', '=', 'affected_person.upazila_id')
            ->join('land_status', 'land_status.land_status_id', '=', 'affected_person.land_status_id')
            ->join('residential_commercial_type AS rc_type', 'rc_type.rc_type_id', '=', 'affected_person.rc_type_id');

        $this->applyProjectFilter($query, 'affected_person.project_id');
        $this->applyDistrictFilter($query, 'affected_person.district_id');
        $this->applyUpazilaFilter($query, 'affected_person.upazila_id');
        $this->applyStatusFilter($query, 'affected_person.status');

        $query->orderBy('affected_person.ep_id');

        $rows = $query->get()->toArray();

        foreach ($rows as &$row) {
            $row['monthly_income'] = (string) $row['monthly_income'];
            $row['monthly_expense'] = (string) $row['monthly_expense'];
        }
        unset($row);

        return [
            'title' => 'EP Register',
            'columns' => $this->columns(),
            'rows' => $rows,
            'summary' => ['total_count' => count($rows)],
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'ep_id',             'label' => 'EP ID',             'type' => 'string'],
            ['key' => 'name',              'label' => 'Name',              'type' => 'string'],
            ['key' => 'father_name',       'label' => 'Father Name',       'type' => 'string'],
            ['key' => 'mother_name',       'label' => 'Mother Name',       'type' => 'string'],
            ['key' => 'nid_number',        'label' => 'NID Number',        'type' => 'string'],
            ['key' => 'mobile',            'label' => 'Mobile',            'type' => 'string'],
            ['key' => 'gender',            'label' => 'Gender',            'type' => 'string'],
            ['key' => 'date_of_birth',     'label' => 'Date of Birth',     'type' => 'date'],
            ['key' => 'religion',          'label' => 'Religion',          'type' => 'string'],
            ['key' => 'occupation',        'label' => 'Occupation',        'type' => 'string'],
            ['key' => 'district',          'label' => 'District',          'type' => 'string'],
            ['key' => 'upazila',           'label' => 'Upazila',           'type' => 'string'],
            ['key' => 'land_status',       'label' => 'Land Status',       'type' => 'string'],
            ['key' => 'rc_type',           'label' => 'RC Type',           'type' => 'string'],
            ['key' => 'monthly_income',    'label' => 'Monthly Income',    'type' => 'money'],
            ['key' => 'monthly_expense',   'label' => 'Monthly Expense',   'type' => 'money'],
            ['key' => 'is_household_head', 'label' => 'Household Head',    'type' => 'boolean'],
            ['key' => 'status',            'label' => 'Status',            'type' => 'string'],
        ];
    }
}
