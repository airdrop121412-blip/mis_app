<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Report\ReportType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 4B-7d-3 — Financial Report Queries (R11–R18).
 *
 * Each report is tested for:
 *   1. Envelope shape (success, message, data, meta)
 *   2. Column presence matching the declared columns() spec
 *   3. Correct data returned (row count, key values)
 *   4. Filter functionality (where applicable)
 *   5. Empty result when no matching data
 *   6. Read-only safety (no DB mutation)
 *
 * All test data created via direct DB inserts.
 * Uses DatabaseTransactions — never RefreshDatabase.
 *
 * Test data chain:
 *   affected_person → ec → compensation
 *   affected_person → allowance (→ vulnerability_rule)
 *   ec → voucher → voucher_compensation → compensation
 *   voucher → payment (→ payment_method)
 */
class FinancialReportsTest extends TestCase
{
    use DatabaseTransactions;

    private int $adminUserId;

    private string $adminToken;

    private int $projectId;

    private int $districtId;

    private int $upazilaId;

    // Seeded master IDs.
    private int $genderId = 1;        // MALE

    private int $religionId = 1;      // ISLAM

    private int $occupationId = 1;    // FARMER

    private int $landStatusId = 1;    // TITLE

    private int $rcTypeId = 1;        // RESIDENTIAL

    // Seeded IDs.
    private int $paymentMethodId = 1; // CASH (seeded)

    // Test data IDs (populated in setUp).
    private int $epId;

    private int $ecId;

    private int $compensationId;

    private int $compensationId2;

    private int $vulnerabilityRuleId;

    private int $allowanceId;

    private int $allowanceId2;

    private int $voucherId;

    private int $voucherCompensationId;

    private int $paymentId;

    protected function setUp(): void
    {
        parent::setUp();

        // ── Admin user (all permissions) ─────────────────────
        $this->adminUserId = DB::table('users')->insertGetId([
            'username' => 'frep_admin_'.Str::random(6),
            'full_name' => 'Financial Report Admin',
            'password_hash' => bcrypt('TestPass@12345'),
            'email' => Str::random(8).'@test.com',
            'mobile' => '017'.rand(10000000, 99999999),
            'is_active' => 1,
        ]);
        DB::table('user_role')->insert(['user_id' => $this->adminUserId, 'role_id' => 1]);
        $this->adminToken = $this->createToken($this->adminUserId);

        // ── Geography ────────────────────────────────────────
        $this->districtId = DB::table('district')->insertGetId([
            'code' => 'FRT'.rand(100, 999),
            'name' => 'Financial Report District',
            'is_active' => 1,
        ]);
        $this->upazilaId = DB::table('upazila')->insertGetId([
            'district_id' => $this->districtId,
            'code' => 'FRU'.rand(100, 999),
            'name' => 'Financial Report Upazila',
            'is_active' => 1,
        ]);

        // ── Project ──────────────────────────────────────────
        $this->projectId = DB::table('project')->insertGetId([
            'project_code' => 'FRT-'.rand(1000, 9999),
            'name' => 'Financial Report Test Project',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'is_active' => 1,
        ]);

        // ── Affected Person ─────────────────────────────────
        $this->epId = DB::table('affected_person')->insertGetId([
            'project_id' => $this->projectId,
            'ep_id' => 'EP'.rand(100000, 999999).'#0001',
            'name' => 'Financial Report Person',
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'nid_number' => (string) rand(1000000000, 9999999999),
            'mobile' => '017'.rand(10000000, 99999999),
            'gender_id' => $this->genderId,
            'religion_id' => $this->religionId,
            'occupation_id' => $this->occupationId,
            'district_id' => $this->districtId,
            'upazila_id' => $this->upazilaId,
            'land_status_id' => $this->landStatusId,
            'rc_type_id' => $this->rcTypeId,
            'monthly_income' => '50000.00',
            'monthly_expense' => '40000.00',
            'status' => 'SUBMITTED',
            'is_household_head' => 1,
        ]);

        // ── EPICAD ───────────────────────────────────────────
        $epicadId = DB::table('epicad')->insertGetId([
            'affected_person_id' => $this->epId,
            'issue_date' => '2025-06-01',
            'print_status' => 'NOT_PRINTED',
        ]);

        // ── EC ───────────────────────────────────────────────
        $this->ecId = DB::table('ec')->insertGetId([
            'affected_person_id' => $this->epId,
            'epicad_id' => $epicadId,
            'ec_number' => 'EC-'.rand(100000, 999999),
            'assessment_date' => '2025-06-15',
            'total_assessed_loss' => '200000.00',
            'total_compensation' => '180000.00',
            'status' => 'APPROVED',
        ]);

        // ── Compensation (LAND) ──────────────────────────────
        $this->compensationId = DB::table('compensation')->insertGetId([
            'ec_id' => $this->ecId,
            'category' => 'LAND',
            'assessed_amount' => '150000.00',
            'approved_amount' => '140000.00',
            'status' => 'APPROVED',
        ]);

        // ── Compensation (CROP) — second category for grouping ─
        $this->compensationId2 = DB::table('compensation')->insertGetId([
            'ec_id' => $this->ecId,
            'category' => 'CROP',
            'assessed_amount' => '50000.00',
            'approved_amount' => '45000.00',
            'status' => 'APPROVED',
        ]);

        // ── Vulnerability Rule (own, not seeded) ─────────────
        $this->vulnerabilityRuleId = DB::table('vulnerability_rule')->insertGetId([
            'rule_code' => 'TEST_RULE',
            'rule_type' => 'INCOME_LIMIT',
            'annual_income_limit' => '120000.00',
            'grant_amount' => '10000.00',
            'is_active' => 1,
        ]);

        // ── Allowance (approved) ────────────────────────────
        $this->allowanceId = DB::table('allowance')->insertGetId([
            'affected_person_id' => $this->epId,
            'vulnerability_rule_id' => $this->vulnerabilityRuleId,
            'is_eligible' => 1,
            'computed_annual_income' => '600000.00',
            'grant_amount' => '10000.00',
            'status' => 'APPROVED',
        ]);

        // ── Allowance (different status for summary) ─────────
        $vulnRule2 = DB::table('vulnerability_rule')->insertGetId([
            'rule_code' => 'TEST_RULE_2',
            'rule_type' => 'CUSTOM',
            'annual_income_limit' => null,
            'grant_amount' => '15000.00',
            'is_active' => 1,
        ]);
        $this->allowanceId2 = DB::table('allowance')->insertGetId([
            'affected_person_id' => $this->epId,
            'vulnerability_rule_id' => $vulnRule2,
            'is_eligible' => 0,
            'computed_annual_income' => null,
            'grant_amount' => '0.00',
            'status' => 'REJECTED',
        ]);

        // ── Voucher ──────────────────────────────────────────
        $this->voucherId = DB::table('voucher')->insertGetId([
            'ec_id' => $this->ecId,
            'voucher_number' => 'VCH-'.rand(100000, 999999),
            'voucher_date' => '2025-07-01',
            'approved_amount' => '185000.00',
            'payment_status' => 'PARTIAL',
            'prepared_by' => $this->adminUserId,
            'verified_by' => null,
            'approved_by' => null,
        ]);

        // ── Voucher ↔ Compensation link ──────────────────────
        $this->voucherCompensationId = DB::table('voucher_compensation')->insertGetId([
            'voucher_id' => $this->voucherId,
            'compensation_id' => $this->compensationId,
            'amount' => '140000.00',
        ]);

        // ── Payment (partial) ────────────────────────────────
        $this->paymentId = DB::table('payment')->insertGetId([
            'voucher_id' => $this->voucherId,
            'payment_method_id' => $this->paymentMethodId,
            'amount' => '100000.00',
            'payment_reference' => 'PAY-REF-001',
            'payment_date' => '2025-07-15',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // HELPER METHODS
    // ═══════════════════════════════════════════════════════════════

    private function createToken(int $userId): string
    {
        $user = User::findOrFail($userId);

        return $user->createToken('test-token')->plainTextToken;
    }

    private function fetchReport(string $type, array $params = []): TestResponse
    {
        $qs = http_build_query($params);

        return $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson("/api/v1/reports/{$type}".($qs ? "?{$qs}" : ''));
    }

    private function assertEnvelope($response, string $expectedType): void
    {
        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => ['title', 'columns', 'rows'],
            'meta' => ['type', 'filters_applied'],
        ]);
        $response->assertJsonPath('meta.type', $expectedType);
        $response->assertJsonPath('data.title', fn ($title) => is_string($title) && strlen($title) > 0);
    }

    private function assertColumnsContain($response, array $expectedKeys): void
    {
        $columns = collect($response->json('data.columns'));
        $keys = $columns->pluck('key')->toArray();

        foreach ($expectedKeys as $key) {
            $this->assertContains($key, $keys, "Missing column key: {$key}");
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // R11 — COMPENSATION SUMMARY
    // ═══════════════════════════════════════════════════════════════

    public function test_r11_compensation_summary_envelope(): void
    {
        $response = $this->fetchReport('compensation-summary');

        $this->assertEnvelope($response, ReportType::CompensationSummary->value);
        $response->assertJsonPath('data.title', 'Compensation Summary');
    }

    public function test_r11_compensation_summary_columns(): void
    {
        $response = $this->fetchReport('compensation-summary');

        $this->assertColumnsContain($response, [
            'category', 'compensation_count', 'total_assessed', 'total_approved', 'percentage',
        ]);
    }

    public function test_r11_compensation_summary_data(): void
    {
        $response = $this->fetchReport('compensation-summary');

        $rows = $response->json('data.rows');
        $this->assertGreaterThanOrEqual(2, count($rows));

        $categories = array_column($rows, 'category');
        $this->assertContains('LAND', $categories);
        $this->assertContains('CROP', $categories);

        // Verify totals.
        $summary = $response->json('data.summary');
        $this->assertGreaterThanOrEqual(2, $summary['total_compensations']);
        $this->assertGreaterThan(0, (float) $summary['total_approved']);
    }

    public function test_r11_compensation_summary_status_filter(): void
    {
        $response = $this->fetchReport('compensation-summary', ['status' => 'APPROVED']);

        $rows = $response->json('data.rows');
        $this->assertNotEmpty($rows);
        // All categories should be present since both are APPROVED.
        $categories = array_column($rows, 'category');
        $this->assertContains('LAND', $categories);
    }

    public function test_r11_compensation_summary_empty_result(): void
    {
        // Filter by non-existent project.
        $response = $this->fetchReport('compensation-summary', ['project_id' => 999999]);

        $response->assertOk();
        $rows = $response->json('data.rows');
        $this->assertEmpty($rows);
    }

    public function test_r11_compensation_summary_read_only(): void
    {
        $before = DB::table('compensation')->count();

        $this->fetchReport('compensation-summary');

        $after = DB::table('compensation')->count();
        $this->assertEquals($before, $after, 'Report query must not mutate compensation table');
    }

    // ═══════════════════════════════════════════════════════════════
    // R12 — ALLOWANCE LIST
    // ═══════════════════════════════════════════════════════════════

    public function test_r12_allowance_list_envelope(): void
    {
        $response = $this->fetchReport('allowance-list');

        $this->assertEnvelope($response, ReportType::AllowanceList->value);
        $response->assertJsonPath('data.title', 'Allowance List');
    }

    public function test_r12_allowance_list_columns(): void
    {
        $response = $this->fetchReport('allowance-list');

        $this->assertColumnsContain($response, [
            'allowance_id', 'ep_id', 'ep_name', 'rule_code', 'rule_type',
            'is_eligible', 'computed_annual_income', 'grant_amount', 'status',
        ]);
    }

    public function test_r12_allowance_list_data(): void
    {
        $response = $this->fetchReport('allowance-list');

        $rows = $response->json('data.rows');
        $this->assertGreaterThanOrEqual(2, count($rows));

        // Check first allowance has expected values.
        $found = collect($rows)->firstWhere('allowance_id', $this->allowanceId);
        $this->assertNotNull($found);
        $this->assertEquals(1, $found['is_eligible']);
        $this->assertEquals('APPROVED', $found['status']);
        $this->assertEquals('10000.00', $found['grant_amount']);
    }

    public function test_r12_allowance_list_project_filter(): void
    {
        $response = $this->fetchReport('allowance-list', ['project_id' => $this->projectId]);

        $rows = $response->json('data.rows');
        $this->assertNotEmpty($rows);
    }

    public function test_r12_allowance_list_status_filter(): void
    {
        $response = $this->fetchReport('allowance-list', ['status' => 'APPROVED']);

        $rows = $response->json('data.rows');
        foreach ($rows as $row) {
            $this->assertEquals('APPROVED', $row['status']);
        }
    }

    public function test_r12_allowance_list_empty_result(): void
    {
        $response = $this->fetchReport('allowance-list', ['project_id' => 999999]);

        $response->assertOk();
        $this->assertEmpty($response->json('data.rows'));
    }

    public function test_r12_allowance_list_read_only(): void
    {
        $before = DB::table('allowance')->count();

        $this->fetchReport('allowance-list');

        $after = DB::table('allowance')->count();
        $this->assertEquals($before, $after, 'Report query must not mutate allowance table');
    }

    // ═══════════════════════════════════════════════════════════════
    // R13 — ALLOWANCE SUMMARY
    // ═══════════════════════════════════════════════════════════════

    public function test_r13_allowance_summary_envelope(): void
    {
        $response = $this->fetchReport('allowance-summary');

        $this->assertEnvelope($response, ReportType::AllowanceSummary->value);
        $response->assertJsonPath('data.title', 'Allowance Summary');
    }

    public function test_r13_allowance_summary_columns(): void
    {
        $response = $this->fetchReport('allowance-summary');

        $this->assertColumnsContain($response, [
            'status', 'allowance_count', 'total_grant', 'percentage',
        ]);
    }

    public function test_r13_allowance_summary_data(): void
    {
        $response = $this->fetchReport('allowance-summary');

        $rows = $response->json('data.rows');
        $this->assertGreaterThanOrEqual(2, count($rows));

        $statuses = array_column($rows, 'status');
        $this->assertContains('APPROVED', $statuses);
        $this->assertContains('REJECTED', $statuses);

        // Total count should match our 2 allowances.
        $totalAllowances = array_sum(array_column($rows, 'allowance_count'));
        $this->assertEquals(2, $totalAllowances);
    }

    public function test_r13_allowance_summary_empty_result(): void
    {
        $response = $this->fetchReport('allowance-summary', ['project_id' => 999999]);

        $response->assertOk();
        $this->assertEmpty($response->json('data.rows'));
    }

    public function test_r13_allowance_summary_read_only(): void
    {
        $before = DB::table('allowance')->count();

        $this->fetchReport('allowance-summary');

        $after = DB::table('allowance')->count();
        $this->assertEquals($before, $after, 'Report query must not mutate allowance table');
    }

    // ═══════════════════════════════════════════════════════════════
    // R14 — VULNERABILITY REPORT
    // ═══════════════════════════════════════════════════════════════

    public function test_r14_vulnerability_report_envelope(): void
    {
        $response = $this->fetchReport('vulnerability-report');

        $this->assertEnvelope($response, ReportType::VulnerabilityReport->value);
        $response->assertJsonPath('data.title', 'Vulnerability Report');
    }

    public function test_r14_vulnerability_report_columns(): void
    {
        $response = $this->fetchReport('vulnerability-report');

        $this->assertColumnsContain($response, [
            'rule_code', 'rule_type', 'annual_income_limit', 'rule_grant_amount',
            'is_active', 'evaluated_count', 'eligible_count', 'ineligible_count', 'total_grants',
        ]);
    }

    public function test_r14_vulnerability_report_data(): void
    {
        $response = $this->fetchReport('vulnerability-report');

        $rows = $response->json('data.rows');
        $this->assertGreaterThanOrEqual(2, count($rows));

        // Check our custom rule.
        $found = collect($rows)->firstWhere('rule_code', 'TEST_RULE');
        $this->assertNotNull($found);
        $this->assertEquals('INCOME_LIMIT', $found['rule_type']);
        $this->assertEquals('120000.00', $found['annual_income_limit']);
        $this->assertEquals('10000.00', $found['rule_grant_amount']);
        $this->assertEquals(1, $found['is_active']);
        $this->assertGreaterThanOrEqual(1, $found['evaluated_count']);
        $this->assertGreaterThanOrEqual(1, $found['eligible_count']);
    }

    public function test_r14_vulnerability_report_empty_result(): void
    {
        // When geo filter is applied, only rules with matching evaluations appear.
        // No allowances match project_id=999999, so rows should be empty.
        $response = $this->fetchReport('vulnerability-report', ['project_id' => 999999]);

        $response->assertOk();
        $rows = $response->json('data.rows');
        $this->assertEmpty($rows);
    }

    public function test_r14_vulnerability_report_read_only(): void
    {
        $beforeRules = DB::table('vulnerability_rule')->count();
        $beforeAllow = DB::table('allowance')->count();

        $this->fetchReport('vulnerability-report');

        $this->assertEquals($beforeRules, DB::table('vulnerability_rule')->count());
        $this->assertEquals($beforeAllow, DB::table('allowance')->count());
    }

    // ═══════════════════════════════════════════════════════════════
    // R15 — VOUCHER LIST
    // ═══════════════════════════════════════════════════════════════

    public function test_r15_voucher_list_envelope(): void
    {
        $response = $this->fetchReport('voucher-list');

        $this->assertEnvelope($response, ReportType::VoucherList->value);
        $response->assertJsonPath('data.title', 'Voucher List');
    }

    public function test_r15_voucher_list_columns(): void
    {
        $response = $this->fetchReport('voucher-list');

        $this->assertColumnsContain($response, [
            'voucher_id', 'voucher_number', 'voucher_date', 'approved_amount',
            'payment_status', 'ec_number', 'ep_id', 'ep_name',
            'prepared_by_name', 'verified_by_name', 'approved_by_name',
        ]);
    }

    public function test_r15_voucher_list_data(): void
    {
        $response = $this->fetchReport('voucher-list');

        $rows = $response->json('data.rows');
        $this->assertGreaterThanOrEqual(1, count($rows));

        $found = collect($rows)->firstWhere('voucher_id', $this->voucherId);
        $this->assertNotNull($found);
        $this->assertEquals('185000.00', $found['approved_amount']);
        $this->assertEquals('PARTIAL', $found['payment_status']);
        $this->assertEquals('2025-07-01', $found['voucher_date']);
        $this->assertNotNull($found['ec_number']);
        // prepared_by_name should be set since we set prepared_by = adminUserId.
        $this->assertEquals('Financial Report Admin', $found['prepared_by_name']);
    }

    public function test_r15_voucher_list_payment_status_filter(): void
    {
        $response = $this->fetchReport('voucher-list', ['payment_status' => 'PARTIAL']);

        $rows = $response->json('data.rows');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertEquals('PARTIAL', $row['payment_status']);
        }
    }

    public function test_r15_voucher_list_project_filter(): void
    {
        $response = $this->fetchReport('voucher-list', ['project_id' => $this->projectId]);

        $rows = $response->json('data.rows');
        $this->assertNotEmpty($rows);
    }

    public function test_r15_voucher_list_empty_result(): void
    {
        $response = $this->fetchReport('voucher-list', ['project_id' => 999999]);

        $response->assertOk();
        $this->assertEmpty($response->json('data.rows'));
    }

    public function test_r15_voucher_list_read_only(): void
    {
        $before = DB::table('voucher')->count();

        $this->fetchReport('voucher-list');

        $after = DB::table('voucher')->count();
        $this->assertEquals($before, $after, 'Report query must not mutate voucher table');
    }

    // ═══════════════════════════════════════════════════════════════
    // R16 — PAYMENT SUMMARY
    // ═══════════════════════════════════════════════════════════════

    public function test_r16_payment_summary_envelope(): void
    {
        $response = $this->fetchReport('payment-summary');

        $this->assertEnvelope($response, ReportType::PaymentSummary->value);
        $response->assertJsonPath('data.title', 'Payment Summary');
    }

    public function test_r16_payment_summary_columns(): void
    {
        $response = $this->fetchReport('payment-summary');

        $this->assertColumnsContain($response, [
            'payment_month', 'payment_count', 'total_amount', 'percentage',
        ]);
    }

    public function test_r16_payment_summary_data(): void
    {
        $response = $this->fetchReport('payment-summary');

        $rows = $response->json('data.rows');
        $this->assertGreaterThanOrEqual(1, count($rows));

        // Payment was in 2025-07.
        $found = collect($rows)->firstWhere('payment_month', '2025-07');
        $this->assertNotNull($found);
        $this->assertEquals('100000.00', $found['total_amount']);
        $this->assertGreaterThanOrEqual(1, $found['payment_count']);
    }

    public function test_r16_payment_summary_empty_result(): void
    {
        $response = $this->fetchReport('payment-summary', ['project_id' => 999999]);

        $response->assertOk();
        $this->assertEmpty($response->json('data.rows'));
    }

    public function test_r16_payment_summary_read_only(): void
    {
        $before = DB::table('payment')->count();

        $this->fetchReport('payment-summary');

        $after = DB::table('payment')->count();
        $this->assertEquals($before, $after, 'Report query must not mutate payment table');
    }

    // ═══════════════════════════════════════════════════════════════
    // R17 — OUTSTANDING AMOUNTS
    // ═══════════════════════════════════════════════════════════════

    public function test_r17_outstanding_amounts_envelope(): void
    {
        $response = $this->fetchReport('outstanding');

        $this->assertEnvelope($response, ReportType::OutstandingAmounts->value);
        $response->assertJsonPath('data.title', 'Outstanding Amounts');
    }

    public function test_r17_outstanding_amounts_columns(): void
    {
        $response = $this->fetchReport('outstanding');

        $this->assertColumnsContain($response, [
            'voucher_id', 'voucher_number', 'voucher_date', 'approved_amount',
            'total_paid', 'outstanding', 'payment_status', 'ec_number', 'ep_id', 'ep_name',
        ]);
    }

    public function test_r17_outstanding_amounts_data(): void
    {
        $response = $this->fetchReport('outstanding');

        $rows = $response->json('data.rows');
        $this->assertGreaterThanOrEqual(1, count($rows));

        $found = collect($rows)->firstWhere('voucher_id', $this->voucherId);
        $this->assertNotNull($found);
        $this->assertEquals('185000.00', $found['approved_amount']);
        $this->assertEquals('100000.00', $found['total_paid']);
        $this->assertEquals('85000.00', $found['outstanding']);
    }

    public function test_r17_outstanding_amounts_project_filter(): void
    {
        $response = $this->fetchReport('outstanding', ['project_id' => $this->projectId]);

        $rows = $response->json('data.rows');
        $this->assertNotEmpty($rows);
        $found = collect($rows)->firstWhere('voucher_id', $this->voucherId);
        $this->assertNotNull($found);
    }

    public function test_r17_outstanding_amounts_empty_result(): void
    {
        $response = $this->fetchReport('outstanding', ['project_id' => 999999]);

        $response->assertOk();
        $this->assertEmpty($response->json('data.rows'));
    }

    public function test_r17_outstanding_amounts_read_only(): void
    {
        $beforeVouchers = DB::table('voucher')->count();
        $beforePayments = DB::table('payment')->count();

        $this->fetchReport('outstanding');

        $this->assertEquals($beforeVouchers, DB::table('voucher')->count());
        $this->assertEquals($beforePayments, DB::table('payment')->count());
    }

    /**
     * R17 AUDIT: outstanding_only=1 must return only vouchers with
     * outstanding amount > 0, and must NOT error (the former havingRaw
     * without GROUP BY would fail with ONLY_FULL_GROUP_BY).
     */
    public function test_r17_outstanding_only_filter_returns_only_unpaid(): void
    {
        // Our setUp() voucher: approved=185000, paid=100000, outstanding=85000 > 0.
        // Add a fully-paid voucher (outstanding = 0).
        $epId2 = DB::table('affected_person')->insertGetId([
            'project_id' => $this->projectId,
            'ep_id' => 'EP'.rand(100000, 999999).'#PAID',
            'name' => 'Fully Paid Person',
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'nid_number' => (string) rand(1000000000, 9999999999),
            'mobile' => '017'.rand(10000000, 99999999),
            'gender_id' => $this->genderId,
            'religion_id' => $this->religionId,
            'occupation_id' => $this->occupationId,
            'district_id' => $this->districtId,
            'upazila_id' => $this->upazilaId,
            'land_status_id' => $this->landStatusId,
            'rc_type_id' => $this->rcTypeId,
            'monthly_income' => '30000.00',
            'monthly_expense' => '25000.00',
            'status' => 'SUBMITTED',
            'is_household_head' => 1,
        ]);
        $epicadId2 = DB::table('epicad')->insertGetId([
            'affected_person_id' => $epId2,
            'issue_date' => '2025-06-01',
            'print_status' => 'NOT_PRINTED',
        ]);
        $ecId2 = DB::table('ec')->insertGetId([
            'affected_person_id' => $epId2,
            'epicad_id' => $epicadId2,
            'ec_number' => 'EC-'.rand(100000, 999999),
            'assessment_date' => '2025-06-15',
            'total_assessed_loss' => '50000.00',
            'total_compensation' => '50000.00',
            'status' => 'APPROVED',
        ]);
        $paidVoucherId = DB::table('voucher')->insertGetId([
            'ec_id' => $ecId2,
            'voucher_number' => 'VCH-'.rand(100000, 999999),
            'voucher_date' => '2025-08-01',
            'approved_amount' => '50000.00',
            'payment_status' => 'PAID',
            'prepared_by' => $this->adminUserId,
        ]);
        // Full payment = approved amount → outstanding = 0.
        DB::table('payment')->insert([
            'voucher_id' => $paidVoucherId,
            'payment_method_id' => $this->paymentMethodId,
            'amount' => '50000.00',
            'payment_reference' => 'PAY-FULL-001',
            'payment_date' => '2025-08-10',
        ]);

        // Request with outstanding_only=1.
        $response = $this->fetchReport('outstanding', ['outstanding_only' => '1']);

        $response->assertOk();
        $rows = $response->json('data.rows');

        // Should only contain vouchers with outstanding > 0.
        $this->assertNotEmpty($rows, 'Should have at least one outstanding voucher');
        foreach ($rows as $row) {
            $this->assertGreaterThan(0, (float) $row['outstanding'],
                "Voucher {$row['voucher_id']} must have outstanding > 0");
        }
        // The fully-paid voucher (outstanding=0) must NOT appear.
        $foundPaid = collect($rows)->firstWhere('voucher_id', $paidVoucherId);
        $this->assertNull($foundPaid, 'Fully paid voucher must not appear when outstanding_only=1');
    }

    /**
     * R17 AUDIT: outstanding_only absent or not '1' should return ALL vouchers.
     */
    public function test_r17_without_outstanding_only_returns_all_vouchers(): void
    {
        // Add a fully-paid voucher.
        $epId2 = DB::table('affected_person')->insertGetId([
            'project_id' => $this->projectId,
            'ep_id' => 'EP'.rand(100000, 999999).'#ALL',
            'name' => 'All Vouchers Person',
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'nid_number' => (string) rand(1000000000, 9999999999),
            'mobile' => '017'.rand(10000000, 99999999),
            'gender_id' => $this->genderId,
            'religion_id' => $this->religionId,
            'occupation_id' => $this->occupationId,
            'district_id' => $this->districtId,
            'upazila_id' => $this->upazilaId,
            'land_status_id' => $this->landStatusId,
            'rc_type_id' => $this->rcTypeId,
            'monthly_income' => '30000.00',
            'monthly_expense' => '25000.00',
            'status' => 'SUBMITTED',
            'is_household_head' => 1,
        ]);
        $epicadId2 = DB::table('epicad')->insertGetId([
            'affected_person_id' => $epId2,
            'issue_date' => '2025-06-01',
            'print_status' => 'NOT_PRINTED',
        ]);
        $ecId2 = DB::table('ec')->insertGetId([
            'affected_person_id' => $epId2,
            'epicad_id' => $epicadId2,
            'ec_number' => 'EC-'.rand(100000, 999999),
            'assessment_date' => '2025-06-15',
            'total_assessed_loss' => '50000.00',
            'total_compensation' => '50000.00',
            'status' => 'APPROVED',
        ]);
        $paidVoucherId = DB::table('voucher')->insertGetId([
            'ec_id' => $ecId2,
            'voucher_number' => 'VCH-'.rand(100000, 999999),
            'voucher_date' => '2025-08-01',
            'approved_amount' => '50000.00',
            'payment_status' => 'PAID',
            'prepared_by' => $this->adminUserId,
        ]);
        DB::table('payment')->insert([
            'voucher_id' => $paidVoucherId,
            'payment_method_id' => $this->paymentMethodId,
            'amount' => '50000.00',
            'payment_reference' => 'PAY-FULL-002',
            'payment_date' => '2025-08-10',
        ]);

        // Request WITHOUT outstanding_only.
        $response = $this->fetchReport('outstanding');

        $response->assertOk();
        $rows = $response->json('data.rows');

        // Should contain BOTH vouchers (outstanding=85000 and outstanding=0).
        $this->assertGreaterThanOrEqual(2, count($rows));
        $foundPaid = collect($rows)->firstWhere('voucher_id', $paidVoucherId);
        $this->assertNotNull($foundPaid, 'Fully paid voucher must appear when outstanding_only is absent');
        $this->assertEquals('0.00', $foundPaid['outstanding']);
    }

    // ═══════════════════════════════════════════════════════════════
    // R18 — PAYMENT METHOD BREAKDOWN
    // ═══════════════════════════════════════════════════════════════

    public function test_r18_payment_method_breakdown_envelope(): void
    {
        $response = $this->fetchReport('payment-method-breakdown');

        $this->assertEnvelope($response, ReportType::PaymentMethodBreakdown->value);
        $response->assertJsonPath('data.title', 'Payment Method Breakdown');
    }

    public function test_r18_payment_method_breakdown_columns(): void
    {
        $response = $this->fetchReport('payment-method-breakdown');

        $this->assertColumnsContain($response, [
            'payment_method', 'payment_count', 'total_amount', 'percentage',
        ]);
    }

    public function test_r18_payment_method_breakdown_data(): void
    {
        $response = $this->fetchReport('payment-method-breakdown');

        $rows = $response->json('data.rows');
        $this->assertGreaterThanOrEqual(1, count($rows));

        // Our payment was method_id=1 (CASH).
        $found = collect($rows)->firstWhere('payment_method', 'Cash');
        $this->assertNotNull($found);
        $this->assertEquals('100000.00', $found['total_amount']);
        $this->assertGreaterThanOrEqual(1, $found['payment_count']);
        // Percentage should be 100% since only one payment method used.
        $this->assertEquals('100.00', $found['percentage']);
    }

    public function test_r18_payment_method_breakdown_project_filter(): void
    {
        $response = $this->fetchReport('payment-method-breakdown', ['project_id' => $this->projectId]);

        $rows = $response->json('data.rows');
        $this->assertNotEmpty($rows);
    }

    public function test_r18_payment_method_breakdown_empty_result(): void
    {
        $response = $this->fetchReport('payment-method-breakdown', ['project_id' => 999999]);

        $response->assertOk();
        $this->assertEmpty($response->json('data.rows'));
    }

    public function test_r18_payment_method_breakdown_read_only(): void
    {
        $before = DB::table('payment')->count();

        $this->fetchReport('payment-method-breakdown');

        $after = DB::table('payment')->count();
        $this->assertEquals($before, $after, 'Report query must not mutate payment table');
    }

    // ═══════════════════════════════════════════════════════════════
    // CROSS-CUTTING TESTS
    // ═══════════════════════════════════════════════════════════════

    public function test_all_financial_types_return_valid_envelope(): void
    {
        $financialTypes = [
            ReportType::CompensationSummary,
            ReportType::AllowanceList,
            ReportType::AllowanceSummary,
            ReportType::VulnerabilityReport,
            ReportType::VoucherList,
            ReportType::PaymentSummary,
            ReportType::OutstandingAmounts,
            ReportType::PaymentMethodBreakdown,
        ];

        foreach ($financialTypes as $type) {
            $response = $this->fetchReport($type->value);

            $response->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('meta.type', $type->value);
        }
    }

    public function test_financial_csv_export_all_types(): void
    {
        $financialTypes = [
            'compensation-summary',
            'allowance-list',
            'allowance-summary',
            'vulnerability-report',
            'voucher-list',
            'payment-summary',
            'outstanding',
            'payment-method-breakdown',
        ];

        foreach ($financialTypes as $type) {
            $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
                ->getJson("/api/v1/reports/{$type}/export?format=csv");

            $response->assertOk();
            $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        }
    }

    public function test_financial_unauthenticated_access_returns_401(): void
    {
        $response = $this->getJson('/api/v1/reports/compensation-summary');
        $response->assertUnauthorized();
    }
}
