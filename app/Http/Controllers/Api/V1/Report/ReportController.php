<?php

namespace App\Http\Controllers\Api\V1\Report;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Report\ReportRequest;
use App\Services\Report\Export\CsvExport;
use App\Services\Report\ReportService;
use App\Services\Report\ReportType;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Single report controller (architecture §18, §5.11 #72–73).
 *
 * Handles both screen (JSON) and export (CSV/XLSX/PDF) for all report
 * types via a registry-based dispatch. No report logic lives here —
 * only routing, permission gating, and response formatting.
 *
 * Permissions: report.view (screen), report.export (file export).
 */
class ReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reportService,
    ) {}

    /**
     * GET /api/v1/reports/{type}
     *
     * Returns the report as JSON (normalised dataset).
     * Requires: report.view permission.
     */
    public function index(string $type, ReportRequest $request): JsonResponse
    {
        $reportType = ReportType::fromSlug($type);

        if ($reportType === null) {
            return ApiResponse::error(
                "Unknown report type [{$type}].",
                'REPORT_TYPE_NOT_FOUND',
                404,
            );
        }

        $dataset = $this->reportService->dispatch($reportType, $request->toFilters());

        return ApiResponse::success($dataset, $dataset['title'], 200, [
            'type' => $reportType->value,
            'filters_applied' => $request->toFilters(),
            'total_rows' => count($dataset['rows']),
        ]);
    }

    /**
     * GET /api/v1/reports/{type}/export
     *
     * Returns the report as a file download (CSV/XLSX/PDF).
     * Requires: report.export permission.
     */
    public function export(string $type, ReportRequest $request): StreamedResponse|JsonResponse
    {
        $reportType = ReportType::fromSlug($type);

        if ($reportType === null) {
            return ApiResponse::error(
                "Unknown report type [{$type}].",
                'REPORT_TYPE_NOT_FOUND',
                404,
            );
        }

        $format = $request->input('format', 'csv');
        $dataset = $this->reportService->dispatch($reportType, $request->toFilters());

        return match ($format) {
            'csv' => $this->downloadCsv($dataset, $reportType),
            'xlsx' => $this->notYetAvailable('XLSX export requires phpoffice/phpspreadsheet — not yet installed.'),
            'pdf' => $this->notYetAvailable('PDF export requires barryvdh/laravel-dompdf — not yet installed.'),
            default => ApiResponse::error("Unsupported export format [{$format}].", 'INVALID_FORMAT', 422),
        };
    }

    private function downloadCsv(array $dataset, ReportType $type): StreamedResponse
    {
        $csv = app(CsvExport::class)->generate($dataset);
        $filename = $type->value.'.csv';

        return response()->streamDownload(
            fn () => print ($csv),
            $filename,
            [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ],
        );
    }

    private function notYetAvailable(string $reason): JsonResponse
    {
        return ApiResponse::error($reason, 'EXPORT_NOT_AVAILABLE', 501);
    }
}
