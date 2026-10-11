<?php

namespace App\Services\Report\ReportQuery;

use App\Models\FamilyMember;
use Illuminate\Support\Facades\DB;

/**
 * R04 — Family Member Register.
 *
 * Lists all family members across affected persons, with relationship,
 * gender, age, occupation, and dependency status.
 *
 * Source: family_member → affected_person → relationship, gender, occupation
 */
class FamilyRegisterQuery extends AbstractReportQuery
{
    public function execute(): array
    {
        $query = FamilyMember::query()
            ->select([
                'family_member.family_member_id',
                'affected_person.ep_id',
                'affected_person.name AS ep_name',
                DB::raw('relationship.name AS relationship'),
                DB::raw('gender.name AS gender'),
                'family_member.age',
                DB::raw('occupation.name AS occupation'),
                'family_member.is_dependent',
            ])
            ->join('affected_person', 'affected_person.affected_person_id', '=', 'family_member.affected_person_id')
            ->join('relationship', 'relationship.relationship_id', '=', 'family_member.relationship_id')
            ->join('gender', 'gender.gender_id', '=', 'family_member.gender_id')
            ->leftJoin('occupation', 'occupation.occupation_id', '=', 'family_member.occupation_id');

        $this->applyProjectFilter($query, 'affected_person.project_id');
        $this->applyDistrictFilter($query, 'affected_person.district_id');
        $this->applyUpazilaFilter($query, 'affected_person.upazila_id');
        $this->applyStatusFilter($query, 'affected_person.status');

        $query->orderBy('affected_person.ep_id')
            ->orderBy('family_member.family_member_id');

        $rows = $query->get()->toArray();

        return [
            'title' => 'Family Member Register',
            'columns' => $this->columns(),
            'rows' => $rows,
            'summary' => ['total_members' => count($rows)],
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'family_member_id', 'label' => 'Member ID',    'type' => 'integer'],
            ['key' => 'ep_id',            'label' => 'EP ID',        'type' => 'string'],
            ['key' => 'ep_name',          'label' => 'EP Name',      'type' => 'string'],
            ['key' => 'relationship',     'label' => 'Relationship', 'type' => 'string'],
            ['key' => 'gender',           'label' => 'Gender',       'type' => 'string'],
            ['key' => 'age',              'label' => 'Age',          'type' => 'integer'],
            ['key' => 'occupation',       'label' => 'Occupation',   'type' => 'string'],
            ['key' => 'is_dependent',     'label' => 'Dependent',    'type' => 'boolean'],
        ];
    }
}
