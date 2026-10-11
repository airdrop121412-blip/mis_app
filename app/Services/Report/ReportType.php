<?php

namespace App\Services\Report;

/**
 * Provisional enumeration of report types (Phase 4B-7d).
 *
 * The 23-report list is derived from the domain model; it is NOT yet
 * verified against an authoritative Phase 1 requirements document.
 * Types marked "provisional" may be renumbered, merged, or removed
 * once the specification is confirmed.
 *
 * Backing values are the URL-safe slugs used in /api/v1/reports/{type}.
 */
enum ReportType: string
{
    // ── Group 1: EP / Population ──────────────────────────────
    case EpRegister = 'ep-register';
    case EpStatusSummary = 'ep-status-summary';
    case EpDemographics = 'ep-demographics';
    case FamilyRegister = 'family-register';
    case EpicadList = 'epicad-list';

    // ── Group 2: EC / Assessment ──────────────────────────────
    case EcRegister = 'ec-register';
    case EcStatusSummary = 'ec-status-summary';
    case AssessmentSummary = 'assessment-summary';
    case LandAssessment = 'land-assessment';
    case CropAssessment = 'crop-assessment';

    // ── Group 3: Compensation / Allowance ─────────────────────
    case CompensationSummary = 'compensation-summary';
    case AllowanceList = 'allowance-list';
    case AllowanceSummary = 'allowance-summary';
    case VulnerabilityReport = 'vulnerability-report';

    // ── Group 4: Voucher / Payment ────────────────────────────
    case VoucherList = 'voucher-list';
    case PaymentSummary = 'payment-summary';
    case OutstandingAmounts = 'outstanding';
    case PaymentMethodBreakdown = 'payment-method-breakdown';

    // ── Group 5: Geographic / Aggregate ───────────────────────
    case DistrictSummary = 'district-summary';
    case UpazilaSummary = 'upazila-summary';
    case ProjectSummary = 'project-summary';

    // ── Group 6: Operational ──────────────────────────────────
    case WorkflowStatus = 'workflow-status';
    case EpicadPrintStatus = 'epicad-print-status';

    // ── Group 7: SWTPP legacy-compatible registers ───────────
    case SwtppEpCompensation = 'swtpp-ep-compensation';
    case SwtppStructureRegister = 'swtpp-structure-register';
    case SwtppPaymentRegister = 'swtpp-payment-register';

    /**
     * Resolve a slug string to its enum case (safe, returns null on miss).
     */
    public static function fromSlug(string $slug): ?self
    {
        return self::tryFrom($slug);
    }

    /**
     * Human-readable label for UI / document headers.
     */
    public function label(): string
    {
        return match ($this) {
            self::EpRegister => 'EP Register',
            self::EpStatusSummary => 'EP Status Summary',
            self::EpDemographics => 'EP Demographic Summary',
            self::FamilyRegister => 'Family Member Register',
            self::EpicadList => 'EPICAD List',
            self::EcRegister => 'EC Register',
            self::EcStatusSummary => 'EC Status Summary',
            self::AssessmentSummary => 'Assessment Summary by Category',
            self::LandAssessment => 'Land Assessment Detail',
            self::CropAssessment => 'Crop Assessment Detail',
            self::CompensationSummary => 'Compensation Summary',
            self::AllowanceList => 'Allowance List',
            self::AllowanceSummary => 'Allowance Summary',
            self::VulnerabilityReport => 'Vulnerability Report',
            self::VoucherList => 'Voucher List',
            self::PaymentSummary => 'Payment Summary',
            self::OutstandingAmounts => 'Outstanding Amounts',
            self::PaymentMethodBreakdown => 'Payment Method Breakdown',
            self::DistrictSummary => 'District Summary',
            self::UpazilaSummary => 'Upazila Summary',
            self::ProjectSummary => 'Project Summary',
            self::WorkflowStatus => 'Workflow Status Report',
            self::EpicadPrintStatus => 'EPICAD Print Status',
            self::SwtppEpCompensation => 'SWTPP EP Compensation Register',
            self::SwtppStructureRegister => 'SWTPP Structure Register',
            self::SwtppPaymentRegister => 'SWTPP Payment Register',
        };
    }
}
