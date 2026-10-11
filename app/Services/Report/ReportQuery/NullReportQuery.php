<?php

namespace App\Services\Report\ReportQuery;

use App\Services\Report\ReportType;

/**
 * Placeholder for report types that have not yet been implemented.
 *
 * Returns an empty dataset with a descriptive title so the dispatch
 * layer can validate the type slug without needing every query to exist.
 */
class NullReportQuery extends AbstractReportQuery
{
    public function __construct(
        private readonly ReportType $type,
        array $filters = [],
    ) {
        parent::__construct($filters);
    }

    public function execute(): array
    {
        return [
            'title' => $this->type->label(),
            'columns' => $this->columns(),
            'rows' => [],
            'summary' => [
                'status' => 'not_implemented',
                'message' => "Report type [{$this->type->value}] is registered but not yet implemented.",
            ],
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'info', 'label' => 'Information', 'type' => 'string'],
        ];
    }
}
