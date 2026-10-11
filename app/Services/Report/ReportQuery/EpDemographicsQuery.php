<?php

namespace App\Services\Report\ReportQuery;

use App\Models\AffectedPerson;
use Illuminate\Support\Facades\DB;

/**
 * R03 — EP Demographic Summary.
 *
 * Breakdown of affected persons by gender, religion, and occupation.
 * Returns three separate sections within a single dataset.
 *
 * Source: affected_person → gender, religion, occupation
 */
class EpDemographicsQuery extends AbstractReportQuery
{
    public function execute(): array
    {
        $baseQuery = AffectedPerson::query()
            ->selectRaw('1');

        $this->applyProjectFilter($baseQuery);
        $this->applyDistrictFilter($baseQuery);
        $this->applyUpazilaFilter($baseQuery);
        $this->applyStatusFilter($baseQuery);

        // We need a clean base query for each dimension.
        $rows = [];

        // Gender breakdown.
        $genderRows = $this->buildDimensionQuery('gender.gender_id', 'gender.name');
        foreach ($genderRows as $row) {
            $row['dimension'] = 'Gender';
            $rows[] = $row;
        }

        // Religion breakdown.
        $religionRows = $this->buildDimensionQuery('affected_person.religion_id', 'religion.name');
        foreach ($religionRows as $row) {
            $row['dimension'] = 'Religion';
            $rows[] = $row;
        }

        // Occupation breakdown.
        $occupationRows = $this->buildDimensionQuery('affected_person.occupation_id', 'occupation.name');
        foreach ($occupationRows as $row) {
            $row['dimension'] = 'Occupation';
            $rows[] = $row;
        }

        // Compute totals per dimension.
        $total = $this->getTotalCount();

        return [
            'title' => 'EP Demographic Summary',
            'columns' => $this->columns(),
            'rows' => $rows,
            'summary' => ['total_ep' => $total],
        ];
    }

    private function buildDimensionQuery(string $groupColumn, string $columnExpr): array
    {
        $query = AffectedPerson::query()
            ->select([
                DB::raw("COALESCE({$columnExpr}, 'N/A') AS dimension_value"),
                DB::raw('COUNT(*) AS ep_count'),
            ])
            ->leftJoin('gender', 'gender.gender_id', '=', 'affected_person.gender_id')
            ->leftJoin('religion', 'religion.religion_id', '=', 'affected_person.religion_id')
            ->leftJoin('occupation', 'occupation.occupation_id', '=', 'affected_person.occupation_id')
            ->groupBy(DB::raw("COALESCE({$columnExpr}, 'N/A')"))
            ->orderBy('ep_count', 'desc');

        $this->applyProjectFilter($query);
        $this->applyDistrictFilter($query);
        $this->applyUpazilaFilter($query);
        $this->applyStatusFilter($query);

        return $query->get()->toArray();
    }

    private function getTotalCount(): int
    {
        $query = AffectedPerson::query()->selectRaw('COUNT(*) AS total');

        $this->applyProjectFilter($query);
        $this->applyDistrictFilter($query);
        $this->applyUpazilaFilter($query);
        $this->applyStatusFilter($query);

        return (int) $query->value('total');
    }

    protected function columns(): array
    {
        return [
            ['key' => 'dimension',       'label' => 'Dimension',       'type' => 'string'],
            ['key' => 'dimension_value', 'label' => 'Value',           'type' => 'string'],
            ['key' => 'ep_count',        'label' => 'EP Count',        'type' => 'integer'],
        ];
    }
}
