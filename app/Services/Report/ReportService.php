<?php

namespace App\Services\Report;

use App\Services\Report\ReportQuery\AllowanceListQuery;
use App\Services\Report\ReportQuery\AllowanceSummaryQuery;
use App\Services\Report\ReportQuery\AssessmentSummaryQuery;
use App\Services\Report\ReportQuery\CompensationSummaryQuery;
use App\Services\Report\ReportQuery\CropAssessmentQuery;
use App\Services\Report\ReportQuery\DistrictSummaryQuery;
use App\Services\Report\ReportQuery\EcRegisterQuery;
use App\Services\Report\ReportQuery\EcStatusSummaryQuery;
use App\Services\Report\ReportQuery\EpDemographicsQuery;
use App\Services\Report\ReportQuery\EpicadListQuery;
use App\Services\Report\ReportQuery\EpicadPrintStatusQuery;
use App\Services\Report\ReportQuery\EpRegisterQuery;
use App\Services\Report\ReportQuery\EpStatusSummaryQuery;
use App\Services\Report\ReportQuery\FamilyRegisterQuery;
use App\Services\Report\ReportQuery\LandAssessmentQuery;
use App\Services\Report\ReportQuery\NullReportQuery;
use App\Services\Report\ReportQuery\OutstandingAmountsQuery;
use App\Services\Report\ReportQuery\PaymentMethodBreakdownQuery;
use App\Services\Report\ReportQuery\PaymentSummaryQuery;
use App\Services\Report\ReportQuery\ProjectSummaryQuery;
use App\Services\Report\ReportQuery\ReportQueryInterface;
use App\Services\Report\ReportQuery\SwtppEpCompensationQuery;
use App\Services\Report\ReportQuery\SwtppPaymentRegisterQuery;
use App\Services\Report\ReportQuery\SwtppStructureRegisterQuery;
use App\Services\Report\ReportQuery\UpazilaSummaryQuery;
use App\Services\Report\ReportQuery\VoucherListQuery;
use App\Services\Report\ReportQuery\VulnerabilityReportQuery;
use App\Services\Report\ReportQuery\WorkflowStatusQuery;

/**
 * Central report registry and dispatcher (architecture §18).
 *
 * Maps ReportType → concrete query class. All queries are resolved from
 * the container so they may inject Eloquent models or services.
 *
 * The service is deliberately thin: it does not contain report logic —
 * that lives in the individual ReportQuery classes.
 */
class ReportService
{
    /**
     * Registry of implemented report types.
     * Provisional — will grow as R01–R23 are implemented in 7d-2/3/4.
     *
     * @var class-string<ReportQueryInterface>[]
     */
    private const QUERY_MAP = [
        // ── Group 1: EP / Population ──────────────────────────
        'ep-register' => EpRegisterQuery::class,
        'ep-status-summary' => EpStatusSummaryQuery::class,
        'ep-demographics' => EpDemographicsQuery::class,
        'family-register' => FamilyRegisterQuery::class,
        'epicad-list' => EpicadListQuery::class,

        // ── Group 2: EC / Assessment ──────────────────────────
        'ec-register' => EcRegisterQuery::class,
        'ec-status-summary' => EcStatusSummaryQuery::class,
        'assessment-summary' => AssessmentSummaryQuery::class,
        'land-assessment' => LandAssessmentQuery::class,
        'crop-assessment' => CropAssessmentQuery::class,

        // ── Group 3: Financial / Compensation ─────────────────
        'compensation-summary' => CompensationSummaryQuery::class,
        'allowance-list' => AllowanceListQuery::class,
        'allowance-summary' => AllowanceSummaryQuery::class,
        'vulnerability-report' => VulnerabilityReportQuery::class,
        'voucher-list' => VoucherListQuery::class,
        'payment-summary' => PaymentSummaryQuery::class,
        'outstanding' => OutstandingAmountsQuery::class,
        'payment-method-breakdown' => PaymentMethodBreakdownQuery::class,

        // ── Group 4: Geographic / Aggregate ──────────────────
        'district-summary' => DistrictSummaryQuery::class,
        'upazila-summary' => UpazilaSummaryQuery::class,
        'project-summary' => ProjectSummaryQuery::class,

        // ── Group 5: Operational ─────────────────────────────
        'workflow-status' => WorkflowStatusQuery::class,
        'epicad-print-status' => EpicadPrintStatusQuery::class,

        // ── Group 6: SWTPP legacy-compatible registers ────────
        'swtpp-ep-compensation' => SwtppEpCompensationQuery::class,
        'swtpp-structure-register' => SwtppStructureRegisterQuery::class,
        'swtpp-payment-register' => SwtppPaymentRegisterQuery::class,
    ];

    /**
     * Dispatch a report query and return the normalised dataset.
     */
    public function dispatch(ReportType $type, array $filters = []): array
    {
        $query = $this->resolveQuery($type, $filters);

        return $query->execute();
    }

    /**
     * Resolve the query class for a given report type.
     */
    public function resolveQuery(ReportType $type, array $filters = []): ReportQueryInterface
    {
        $class = self::QUERY_MAP[$type->value] ?? null;

        if ($class === null) {
            return new NullReportQuery($type, $filters);
        }

        return app($class, ['filters' => $filters]);
    }

    /**
     * Check if a report type has an actual implementation (not just NullReportQuery).
     */
    public function isImplemented(ReportType $type): bool
    {
        return array_key_exists($type->value, self::QUERY_MAP);
    }

    /**
     * Get all registered report types.
     *
     * @return ReportType[]
     */
    public function registeredTypes(): array
    {
        return ReportType::cases();
    }
}
