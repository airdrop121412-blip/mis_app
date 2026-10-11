<?php

namespace App\Services\Report\ReportQuery;

use App\Models\CropAssessment;
use Illuminate\Support\Facades\DB;

/**
 * R10 — Crop Assessment Detail.
 *
 * Detailed listing of all crop assessments with crop details, season,
 * cultivated area, damage percentage, rate, and computed assessed loss.
 *
 * Source: crop_assessment → crop → land → affected_person,
 *         crop_type, crop_season, unit
 */
class CropAssessmentQuery extends AbstractReportQuery
{
    public function execute(): array
    {
        $query = CropAssessment::query()
            ->select([
                'crop_assessment.crop_assessment_id',
                'affected_person.ep_id',
                'affected_person.name AS ep_name',
                DB::raw('land_type.name AS land_type'),
                DB::raw('crop_type.name AS crop_type'),
                DB::raw('crop_season.name AS season'),
                'crop.cultivated_area',
                DB::raw('area_unit.name AS area_unit'),
                'crop.damage_percentage',
                'crop_assessment.rate_per_unit',
                'crop_assessment.assessed_loss',
            ])
            ->join('crop', 'crop.crop_id', '=', 'crop_assessment.crop_id')
            ->join('land', 'land.land_id', '=', 'crop.land_id')
            ->join('affected_person', 'affected_person.affected_person_id', '=', 'land.affected_person_id')
            ->join('crop_type', 'crop_type.crop_type_id', '=', 'crop.crop_type_id')
            ->join('crop_season', 'crop_season.crop_season_id', '=', 'crop.crop_season_id')
            ->leftJoin('land_type', 'land_type.land_type_id', '=', 'land.land_type_id')
            ->leftJoin('unit AS area_unit', 'area_unit.unit_id', '=', 'crop.area_unit_id');

        $this->applyProjectFilter($query, 'affected_person.project_id');
        $this->applyDistrictFilter($query, 'affected_person.district_id');
        $this->applyUpazilaFilter($query, 'affected_person.upazila_id');

        // Crop type and season filters.
        if (! empty($this->filters['crop_type_id'])) {
            $query->where('crop.crop_type_id', $this->filters['crop_type_id']);
        }
        if (! empty($this->filters['season_id'])) {
            $query->where('crop.crop_season_id', $this->filters['season_id']);
        }

        $query->orderBy('crop_assessment.crop_assessment_id');

        $rows = $query->get()->toArray();

        // Cast numeric columns to string.
        foreach ($rows as &$row) {
            $row['rate_per_unit'] = (string) $row['rate_per_unit'];
            $row['assessed_loss'] = (string) $row['assessed_loss'];
            $row['cultivated_area'] = (string) $row['cultivated_area'];
            $row['damage_percentage'] = (string) $row['damage_percentage'];
        }
        unset($row);

        $totalLoss = array_sum(array_map(fn ($r) => (float) $r['assessed_loss'], $rows));

        return [
            'title' => 'Crop Assessment Detail',
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
            ['key' => 'crop_assessment_id', 'label' => 'Assessment ID',   'type' => 'integer'],
            ['key' => 'ep_id',              'label' => 'EP ID',            'type' => 'string'],
            ['key' => 'ep_name',            'label' => 'EP Name',          'type' => 'string'],
            ['key' => 'land_type',          'label' => 'Land Type',        'type' => 'string'],
            ['key' => 'crop_type',          'label' => 'Crop Type',        'type' => 'string'],
            ['key' => 'season',             'label' => 'Season',           'type' => 'string'],
            ['key' => 'cultivated_area',    'label' => 'Cultivated Area',  'type' => 'number'],
            ['key' => 'area_unit',          'label' => 'Area Unit',        'type' => 'string'],
            ['key' => 'damage_percentage',  'label' => 'Damage %',         'type' => 'number'],
            ['key' => 'rate_per_unit',      'label' => 'Rate Per Unit',    'type' => 'money'],
            ['key' => 'assessed_loss',      'label' => 'Assessed Loss',    'type' => 'money'],
        ];
    }
}
