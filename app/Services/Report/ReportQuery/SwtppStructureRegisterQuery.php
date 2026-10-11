<?php

namespace App\Services\Report\ReportQuery;

use App\Models\Infrastructure;
use Illuminate\Support\Facades\DB;

/** SWTPP-compatible replacement for the legacy Structure Information report. */
class SwtppStructureRegisterQuery extends AbstractReportQuery
{
    public function execute(): array
    {
        $query = Infrastructure::query()->select([
            'infrastructure.infrastructure_id', 'affected_person.ep_id',
            DB::raw('affected_person.name AS ep_name'),
            DB::raw('infrastructure_type.name AS structure_type'),
            'infrastructure.length', 'infrastructure.width', 'infrastructure.area_sqft',
            'infrastructure.damage_percentage', 'infrastructure_assessment.rate_per_sqft',
            'infrastructure_assessment.assessed_loss',
        ])->join('affected_person', 'affected_person.affected_person_id', '=', 'infrastructure.affected_person_id')
            ->join('infrastructure_type', 'infrastructure_type.infrastructure_type_id', '=', 'infrastructure.infrastructure_type_id')
            ->leftJoin('infrastructure_assessment', 'infrastructure_assessment.infrastructure_id', '=', 'infrastructure.infrastructure_id');
        $this->applyProjectFilter($query, 'affected_person.project_id');
        $this->applyDistrictFilter($query, 'affected_person.district_id');
        $this->applyUpazilaFilter($query, 'affected_person.upazila_id');
        $rows = $query->orderBy('infrastructure.infrastructure_id')->get()->map(function (Infrastructure $model): array {
            $row = $model->getAttributes();
            foreach (['length', 'width', 'area_sqft', 'damage_percentage', 'rate_per_sqft', 'assessed_loss'] as $key) {
                $row[$key] = $row[$key] === null ? null : (string) $row[$key];
            }

            return $row;
        })->all();

        return ['title' => 'SWTPP Structure Register', 'columns' => $this->columns(), 'rows' => $rows,
            'summary' => ['total_structures' => count($rows), 'total_compensation' => number_format(array_sum(array_map(fn ($row) => (float) ($row['assessed_loss'] ?? 0), $rows)), 2, '.', '')]];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'infrastructure_id', 'label' => 'Structure ID', 'type' => 'integer'],
            ['key' => 'ep_id', 'label' => 'EP ID', 'type' => 'string'],
            ['key' => 'ep_name', 'label' => 'EP Name', 'type' => 'string'],
            ['key' => 'structure_type', 'label' => 'Structure Type', 'type' => 'string'],
            ['key' => 'length', 'label' => 'Length', 'type' => 'number'],
            ['key' => 'width', 'label' => 'Width', 'type' => 'number'],
            ['key' => 'area_sqft', 'label' => 'Area', 'type' => 'number'],
            ['key' => 'damage_percentage', 'label' => 'Damage %', 'type' => 'percentage'],
            ['key' => 'rate_per_sqft', 'label' => 'Structure Rate', 'type' => 'money'],
            ['key' => 'assessed_loss', 'label' => 'DC Compensation', 'type' => 'money'],
        ];
    }
}
