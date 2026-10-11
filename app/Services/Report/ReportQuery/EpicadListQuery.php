<?php

namespace App\Services\Report\ReportQuery;

use App\Models\Epicad;

/**
 * R05 — EPICAD List.
 *
 * Lists all EPICAD forms with affected person details and print status.
 * Used for tracking physical EPICAD document generation.
 *
 * Source: epicad → affected_person
 */
class EpicadListQuery extends AbstractReportQuery
{
    public function execute(): array
    {
        $query = Epicad::query()
            ->select([
                'epicad.epicad_id',
                'affected_person.ep_id',
                'affected_person.name',
                'affected_person.nid_number',
                'affected_person.mobile',
                'epicad.issue_date',
                'epicad.print_status',
            ])
            ->join('affected_person', 'affected_person.affected_person_id', '=', 'epicad.affected_person_id');

        $this->applyProjectFilter($query, 'affected_person.project_id');
        $this->applyDistrictFilter($query, 'affected_person.district_id');
        $this->applyUpazilaFilter($query, 'affected_person.upazila_id');

        // Apply print_status filter if present.
        if (! empty($this->filters['print_status'])) {
            $query->where('epicad.print_status', $this->filters['print_status']);
        }

        $query->orderBy('epicad.epicad_id');

        $rows = $query->get()->toArray();

        return [
            'title' => 'EPICAD List',
            'columns' => $this->columns(),
            'rows' => $rows,
            'summary' => ['total_count' => count($rows)],
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'epicad_id',    'label' => 'EPICAD ID',    'type' => 'integer'],
            ['key' => 'ep_id',        'label' => 'EP ID',        'type' => 'string'],
            ['key' => 'name',         'label' => 'Name',         'type' => 'string'],
            ['key' => 'nid_number',   'label' => 'NID Number',   'type' => 'string'],
            ['key' => 'mobile',       'label' => 'Mobile',       'type' => 'string'],
            ['key' => 'issue_date',   'label' => 'Issue Date',   'type' => 'date'],
            ['key' => 'print_status', 'label' => 'Print Status', 'type' => 'string'],
        ];
    }
}
