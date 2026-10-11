<?php

namespace App\Services\Report\ReportQuery;

use App\Models\LandAssessment;
use Illuminate\Support\Facades\DB;

/**
 * R09 — Land Assessment Detail.
 *
 * Detailed listing of all land assessments with land details, damage
 * type, area, rate, and computed assessed loss.
 *
 * Source: land_assessment → land → affected_person, land_type, damage_type, unit
 */
class LandAssessmentQuery extends AbstractReportQuery
{
    public function execute(): array
    {
        $query = LandAssessment::query()
            ->select([
                'land_assessment.land_assessment_id',
                'affected_person.ep_id',
                'affected_person.name AS ep_name',
                DB::raw('land_type.name AS land_type'),
                'land.land_area',
                DB::raw('land_area_unit.name AS land_area_unit'),
                DB::raw('land_damage_type.name AS damage_type'),
                'land_assessment.affected_area',
                DB::raw('area_unit.name AS affected_area_unit'),
                'land_assessment.rate_per_unit',
                'land_assessment.assessed_loss',
            ])
            ->join('land', 'land.land_id', '=', 'land_assessment.land_id')
            ->join('affected_person', 'affected_person.affected_person_id', '=', 'land.affected_person_id')
            ->join('land_type', 'land_type.land_type_id', '=', 'land.land_type_id')
            ->join('land_damage_type', 'land_damage_type.land_damage_type_id', '=', 'land_assessment.damage_type_id')
            ->join('unit AS area_unit', 'area_unit.unit_id', '=', 'land_assessment.unit_id')
            ->leftJoin('unit AS land_area_unit', 'land_area_unit.unit_id', '=', 'land.land_area_unit_id');

        $this->applyProjectFilter($query, 'affected_person.project_id');
        $this->applyDistrictFilter($query, 'affected_person.district_id');
        $this->applyUpazilaFilter($query, 'affected_person.upazila_id');

        // land_type_id filter
        if (! empty($this->filters['land_type_id'])) {
            $query->where('land.land_type_id', $this->filters['land_type_id']);
        }

        $query->orderBy('land_assessment.land_assessment_id');

        $rows = $query->get()->toArray();

        // Cast money columns to string.
        foreach ($rows as &$row) {
            $row['rate_per_unit'] = (string) $row['rate_per_unit'];
            $row['assessed_loss'] = (string) $row['assessed_loss'];
            $row['land_area'] = (string) $row['land_area'];
            $row['affected_area'] = (string) $row['affected_area'];
        }
        unset($row);

        $totalLoss = array_sum(array_map(fn ($r) => (float) $r['assessed_loss'], $rows));

        return [
            'title' => 'Land Assessment Detail',
            'columns' => $this->columns(),
            'rows' => $rows,
            'summary' => [
                'total_assessments' => count($rows),
                'total_assessed' => number_format($totalLoss, 2, '.', ''),
            ],
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'land_assessment_id', 'label' => 'Assessment ID',  'type' => 'integer'],
            ['key' => 'ep_id',              'label' => 'EP ID',           'type' => 'string'],
            ['key' => 'ep_name',            'label' => 'EP Name',         'type' => 'string'],
            ['key' => 'land_type',          'label' => 'Land Type',       'type' => 'string'],
            ['key' => 'land_area',          'label' => 'Land Area',       'type' => 'number'],
            ['key' => 'land_area_unit',     'label' => 'Area Unit',       'type' => 'string'],
            ['key' => 'damage_type',        'label' => 'Damage Type',     'type' => 'string'],
            ['key' => 'affected_area',      'label' => 'Affected Area',   'type' => 'number'],
            ['key' => 'affected_area_unit', 'label' => 'Affected Unit',   'type' => 'string'],
            ['key' => 'rate_per_unit',      'label' => 'Rate Per Unit',   'type' => 'money'],
            ['key' => 'assessed_loss',      'label' => 'Assessed Loss',   'type' => 'money'],
        ];
    }
}
