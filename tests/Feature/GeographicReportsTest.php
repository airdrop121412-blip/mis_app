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
 * Phase 4B-7d-4 — Geographic / Aggregate / Operational Reports (R19–R23).
 *
 * Each report is tested for:
 *   1. Envelope shape (success, message, data, meta)
 *   2. Column presence matching the declared columns() spec
 *   3. Correct data returned (row count, key values)
 *   4. Filter functionality (where applicable)
 *   5. Empty result when no matching data
 *   6. Read-only safety (no DB mutation)
 *
 * Test data chain:
 *   district → upazila → project → affected_person → ec → compensation
 *   affected_person → epicad, allowance
 *   ec → voucher → payment
 */
class GeographicReportsTest extends TestCase
{
    use DatabaseTransactions;

    private int $adminUserId;

    private string $adminToken;

    private int $projectId;

    private int $districtId;

    private int $upazilaId;

    // Seeded master IDs.
    private int $genderId = 1;

    private int $religionId = 1;

    private int $occupationId = 1;

    private int $landStatusId = 1;

    private int $rcTypeId = 1;

    private int $paymentMethodId = 1;

    // Test data IDs.
    private int $epId;

    private int $ecId;

    private int $epicadId;

    private int $vulnerabilityRuleId;

    protected function setUp(): void
    {
        parent::setUp();

        // ── Admin user ───────────────────────────────────────
        $this->adminUserId = DB::table('users')->insertGetId([
            'username' => 'geo_admin_'.Str::random(6),
            'full_name' => 'Geo Report Admin',
            'password_hash' => bcrypt('TestPass@12345'),
            'email' => Str::random(8).'@test.com',
            'mobile' => '017'.rand(10000000, 99999999),
            'is_active' => 1,
        ]);
        DB::table('user_role')->insert(['user_id' => $this->adminUserId, 'role_id' => 1]);
        $this->adminToken = $this->createToken($this->adminUserId);

        // ── Geography ────────────────────────────────────────
        $this->districtId = DB::table('district')->insertGetId([
            'code' => 'GEO'.rand(100, 999),
            'name' => 'Geo Report District',
            'is_active' => 1,
        ]);
        $this->upazilaId = DB::table('upazila')->insertGetId([
            'district_id' => $this->districtId,
            'code' => 'GUZ'.rand(100, 999),
            'name' => 'Geo Report Upazila',
            'is_active' => 1,
        ]);

        // ── Project ──────────────────────────────────────────
        $this->projectId = DB::table('project')->insertGetId([
            'project_code' => 'GEO-'.rand(1000, 9999),
            'name' => 'Geo Report Test Project',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'is_active' => 1,
        ]);

        // ── Affected Person ─────────────────────────────────
        $this->epId = DB::table('affected_person')->insertGetId([
            'project_id' => $this->projectId,
            'ep_id' => 'EP'.rand(100000, 999999).'#0001',
            'name' => 'Geo Report Person',
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
        $this->epicadId = DB::table('epicad')->insertGetId([
            'affected_person_id' => $this->epId,
            'issue_date' => '2025-06-01',
            'print_status' => 'PRINTED',
        ]);

        // ── EC ───────────────────────────────────────────────
        $this->ecId = DB::table('ec')->insertGetId([
            'affected_person_id' => $this->epId,
            'epicad_id' => $this->epicadId,
            'ec_number' => 'EC-'.rand(100000, 999999),
            'assessment_date' => '2025-06-15',
            'total_assessed_loss' => '200000.00',
            'total_compensation' => '180000.00',
            'status' => 'APPROVED',
        ]);

        // ── Compensation ─────────────────────────────────────
        DB::table('compensation')->insert([
            'ec_id' => $this->ecId,
            'category' => 'LAND',
            'assessed_amount' => '150000.00',
            'approved_amount' => '140000.00',
            'status' => 'APPROVED',
        ]);

        // ── Vulnerability Rule + Allowance ───────────────────
        $this->vulnerabilityRuleId = DB::table('vulnerability_rule')->insertGetId([
            'rule_code' => 'GEO_TEST',
            'rule_type' => 'INCOME_LIMIT',
            'annual_income_limit' => '120000.00',
            'grant_amount' => '10000.00',
            'is_active' => 1,
        ]);
        DB::table('allowance')->insert([
            'affected_person_id' => $this->epId,
            'vulnerability_rule_id' => $this->vulnerabilityRuleId,
            'is_eligible' => 1,
            'computed_annual_income' => '600000.00',
            'grant_amount' => '10000.00',
            'status' => 'APPROVED',
        ]);

        // ── Voucher + Payment ────────────────────────────────
        $voucherId = DB::table('voucher')->insertGetId([
            'ec_id' => $this->ecId,
            'voucher_number' => 'VCH-'.rand(100000, 999999),
            'voucher_date' => '2025-07-01',
            'approved_amount' => '140000.00',
            'payment_status' => 'PAID',
            'prepared_by' => $this->adminUserId,
        ]);
        DB::table('payment')->insert([
            'voucher_id' => $voucherId,
            'payment_method_id' => $this->paymentMethodId,
            'amount' => '140000.00',
            'payment_reference' => 'PAY-GEO-001',
            'payment_date' => '2025-07-15',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // HELPERS
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
    // R19 — DISTRICT SUMMARY
    // ═══════════════════════════════════════════════════════════════

    public function test_r19_district_summary_envelope(): void
    {
        $response = $this->fetchReport('district-summary');

        $this->assertEnvelope($response, ReportType::DistrictSummary->value);
        $response->assertJsonPath('data.title', 'District Summary');
    }

    public function test_r19_district_summary_columns(): void
    {
        $response = $this->fetchReport('district-summary');

        $this->assertColumnsContain($response, [
            'district_name', 'district_code', 'ep_count', 'ec_count',
            'total_assessed', 'total_compensation', 'allowance_count', 'total_grant',
        ]);
    }

    public function test_r19_district_summary_data(): void
    {
        $response = $this->fetchReport('district-summary');

        $rows = $response->json('data.rows');
        $this->assertGreaterThanOrEqual(1, count($rows));

        $found = collect($rows)->firstWhere('district_id', $this->districtId);
        $this->assertNotNull($found);
        $this->assertEquals(1, $found['ep_count']);
        $this->assertEquals(1, $found['ec_count']);
        $this->assertGreaterThan(0, (float) $found['total_assessed']);
    }

    public function test_r19_district_summary_project_filter(): void
    {
        $response = $this->fetchReport('district-summary', ['project_id' => $this->projectId]);

        $rows = $response->json('data.rows');
        $this->assertNotEmpty($rows);
        $found = collect($rows)->firstWhere('district_id', $this->districtId);
        $this->assertNotNull($found);
    }

    public function test_r19_district_summary_empty_result(): void
    {
        $response = $this->fetchReport('district-summary', ['project_id' => 999999]);

        $response->assertOk();
        $this->assertEmpty($response->json('data.rows'));
    }

    public function test_r19_district_summary_read_only(): void
    {
        $before = DB::table('district')->count();

        $this->fetchReport('district-summary');

        $this->assertEquals($before, DB::table('district')->count());
    }

    // ═══════════════════════════════════════════════════════════════
    // R20 — UPAZILA SUMMARY
    // ═══════════════════════════════════════════════════════════════

    public function test_r20_upazila_summary_envelope(): void
    {
        $response = $this->fetchReport('upazila-summary');

        $this->assertEnvelope($response, ReportType::UpazilaSummary->value);
        $response->assertJsonPath('data.title', 'Upazila Summary');
    }

    public function test_r20_upazila_summary_columns(): void
    {
        $response = $this->fetchReport('upazila-summary');

        $this->assertColumnsContain($response, [
            'upazila_name', 'upazila_code', 'district_name',
            'ep_count', 'ec_count', 'total_assessed', 'total_compensation',
        ]);
    }

    public function test_r20_upazila_summary_data(): void
    {
        $response = $this->fetchReport('upazila-summary');

        $rows = $response->json('data.rows');
        $this->assertGreaterThanOrEqual(1, count($rows));

        $found = collect($rows)->firstWhere('upazila_id', $this->upazilaId);
        $this->assertNotNull($found);
        $this->assertEquals(1, $found['ep_count']);
        $this->assertEquals('Geo Report District', $found['district_name']);
    }

    public function test_r20_upazila_summary_district_filter(): void
    {
        $response = $this->fetchReport('upazila-summary', ['district_id' => $this->districtId]);

        $rows = $response->json('data.rows');
        $this->assertNotEmpty($rows);
        // All returned upazilas should belong to our district.
        $found = collect($rows)->firstWhere('upazila_id', $this->upazilaId);
        $this->assertNotNull($found);
        $this->assertEquals('Geo Report District', $found['district_name']);
    }

    public function test_r20_upazila_summary_empty_result(): void
    {
        $response = $this->fetchReport('upazila-summary', ['project_id' => 999999]);

        $response->assertOk();
        $this->assertEmpty($response->json('data.rows'));
    }

    public function test_r20_upazila_summary_read_only(): void
    {
        $before = DB::table('upazila')->count();

        $this->fetchReport('upazila-summary');

        $this->assertEquals($before, DB::table('upazila')->count());
    }

    // ═══════════════════════════════════════════════════════════════
    // R21 — PROJECT SUMMARY
    // ═══════════════════════════════════════════════════════════════

    public function test_r21_project_summary_envelope(): void
    {
        $response = $this->fetchReport('project-summary');

        $this->assertEnvelope($response, ReportType::ProjectSummary->value);
        $response->assertJsonPath('data.title', 'Project Summary');
    }

    public function test_r21_project_summary_columns(): void
    {
        $response = $this->fetchReport('project-summary');

        $this->assertColumnsContain($response, [
            'project_code', 'project_name', 'ep_count', 'ec_count',
            'total_assessed', 'total_compensation', 'allowance_count',
            'total_grant', 'voucher_count', 'total_paid',
        ]);
    }

    public function test_r21_project_summary_data(): void
    {
        $response = $this->fetchReport('project-summary');

        $rows = $response->json('data.rows');
        $this->assertGreaterThanOrEqual(1, count($rows));

        $found = collect($rows)->firstWhere('project_id', $this->projectId);
        $this->assertNotNull($found);
        $this->assertEquals(1, $found['ep_count']);
        $this->assertEquals(1, $found['ec_count']);
        $this->assertEquals(1, $found['voucher_count']);
        $this->assertGreaterThan(0, (float) $found['total_paid']);
    }

    public function test_r21_project_summary_project_filter(): void
    {
        $response = $this->fetchReport('project-summary', ['project_id' => $this->projectId]);

        $rows = $response->json('data.rows');
        $this->assertCount(1, $rows);
        $this->assertEquals($this->projectId, $rows[0]['project_id']);
    }

    public function test_r21_project_summary_empty_result(): void
    {
        $response = $this->fetchReport('project-summary', ['project_id' => 999999]);

        $response->assertOk();
        $this->assertEmpty($response->json('data.rows'));
    }

    public function test_r21_project_summary_read_only(): void
    {
        $before = DB::table('project')->count();

        $this->fetchReport('project-summary');

        $this->assertEquals($before, DB::table('project')->count());
    }

    // ═══════════════════════════════════════════════════════════════
    // R22 — WORKFLOW STATUS
    // ═══════════════════════════════════════════════════════════════

    public function test_r22_workflow_status_envelope(): void
    {
        $response = $this->fetchReport('workflow-status');

        $this->assertEnvelope($response, ReportType::WorkflowStatus->value);
        $response->assertJsonPath('data.title', 'Workflow Status Report');
    }

    public function test_r22_workflow_status_columns(): void
    {
        $response = $this->fetchReport('workflow-status');

        $this->assertColumnsContain($response, ['entity_type', 'status', 'count']);
    }

    public function test_r22_workflow_status_data(): void
    {
        $response = $this->fetchReport('workflow-status');

        $rows = $response->json('data.rows');
        $this->assertGreaterThanOrEqual(1, count($rows));

        // Should have at least an EP entry with SUBMITTED status.
        $epRows = collect($rows)->where('entity_type', 'EP');
        $this->assertTrue($epRows->isNotEmpty(), 'Should have EP entity type entries');
        $submittedEp = $epRows->firstWhere('status', 'SUBMITTED');
        $this->assertNotNull($submittedEp, 'Should have SUBMITTED EP');
        $this->assertGreaterThanOrEqual(1, $submittedEp['count']);

        // Should have EC entry.
        $ecRows = collect($rows)->where('entity_type', 'EC');
        $this->assertTrue($ecRows->isNotEmpty(), 'Should have EC entity type entries');

        // Summary should have entity_totals.
        $summary = $response->json('data.summary.entity_totals');
        $this->assertArrayHasKey('EP', $summary);
        $this->assertArrayHasKey('EC', $summary);
    }

    public function test_r22_workflow_status_project_filter(): void
    {
        $response = $this->fetchReport('workflow-status', ['project_id' => $this->projectId]);

        $rows = $response->json('data.rows');
        $this->assertNotEmpty($rows);
        // Should still have EP entries for this project.
        $epRows = collect($rows)->where('entity_type', 'EP');
        $this->assertTrue($epRows->isNotEmpty());
    }

    public function test_r22_workflow_status_empty_result(): void
    {
        $response = $this->fetchReport('workflow-status', ['project_id' => 999999]);

        $response->assertOk();
        $this->assertEmpty($response->json('data.rows'));
    }

    public function test_r22_workflow_status_read_only(): void
    {
        $counts = [
            'affected_person' => DB::table('affected_person')->count(),
            'ec' => DB::table('ec')->count(),
            'compensation' => DB::table('compensation')->count(),
            'voucher' => DB::table('voucher')->count(),
            'allowance' => DB::table('allowance')->count(),
        ];

        $this->fetchReport('workflow-status');

        $this->assertEquals($counts['affected_person'], DB::table('affected_person')->count());
        $this->assertEquals($counts['ec'], DB::table('ec')->count());
        $this->assertEquals($counts['compensation'], DB::table('compensation')->count());
        $this->assertEquals($counts['voucher'], DB::table('voucher')->count());
        $this->assertEquals($counts['allowance'], DB::table('allowance')->count());
    }

    // ═══════════════════════════════════════════════════════════════
    // R23 — EPICAD PRINT STATUS
    // ═══════════════════════════════════════════════════════════════

    public function test_r23_epicad_print_status_envelope(): void
    {
        $response = $this->fetchReport('epicad-print-status');

        $this->assertEnvelope($response, ReportType::EpicadPrintStatus->value);
        $response->assertJsonPath('data.title', 'EPICAD Print Status');
    }

    public function test_r23_epicad_print_status_columns(): void
    {
        $response = $this->fetchReport('epicad-print-status');

        $this->assertColumnsContain($response, ['print_status', 'print_count', 'percentage']);
    }

    public function test_r23_epicad_print_status_data(): void
    {
        $response = $this->fetchReport('epicad-print-status');

        $rows = $response->json('data.rows');
        $this->assertGreaterThanOrEqual(1, count($rows));

        // Our EPICAD has print_status = PRINTED.
        $printed = collect($rows)->firstWhere('print_status', 'PRINTED');
        $this->assertNotNull($printed);
        $this->assertGreaterThanOrEqual(1, $printed['print_count']);
        $this->assertGreaterThan(0, (float) $printed['percentage']);
    }

    public function test_r23_epicad_print_status_project_filter(): void
    {
        $response = $this->fetchReport('epicad-print-status', ['project_id' => $this->projectId]);

        $rows = $response->json('data.rows');
        $this->assertNotEmpty($rows);
    }

    public function test_r23_epicad_print_status_empty_result(): void
    {
        $response = $this->fetchReport('epicad-print-status', ['project_id' => 999999]);

        $response->assertOk();
        $this->assertEmpty($response->json('data.rows'));
    }

    public function test_r23_epicad_print_status_read_only(): void
    {
        $before = DB::table('epicad')->count();

        $this->fetchReport('epicad-print-status');

        $this->assertEquals($before, DB::table('epicad')->count());
    }

    // ═══════════════════════════════════════════════════════════════
    // CROSS-CUTTING TESTS
    // ═══════════════════════════════════════════════════════════════

    public function test_all_geo_types_return_valid_envelope(): void
    {
        $types = [
            ReportType::DistrictSummary,
            ReportType::UpazilaSummary,
            ReportType::ProjectSummary,
            ReportType::WorkflowStatus,
            ReportType::EpicadPrintStatus,
        ];

        foreach ($types as $type) {
            $response = $this->fetchReport($type->value);

            $response->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('meta.type', $type->value);
        }
    }

    public function test_geo_csv_export_all_types(): void
    {
        $types = [
            'district-summary',
            'upazila-summary',
            'project-summary',
            'workflow-status',
            'epicad-print-status',
        ];

        foreach ($types as $type) {
            $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
                ->getJson("/api/v1/reports/{$type}/export?format=csv");

            $response->assertOk();
            $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        }
    }

    public function test_geo_unauthenticated_access_returns_401(): void
    {
        $response = $this->getJson('/api/v1/reports/district-summary');
        $response->assertUnauthorized();
    }

    // ═══════════════════════════════════════════════════════════════
    // AUDIT REGRESSION: SUM(DISTINCT) duplicate-amount bug
    //
    // When two EPs in the same district have identical assessed_loss
    // or grant_amount values, SUM(DISTINCT) would silently drop one.
    // The fix uses pre-aggregated subqueries with plain SUM().
    // These tests prove the correct totals are returned.
    // ═══════════════════════════════════════════════════════════════

    public function test_r19_duplicate_amounts_not_dropped(): void
    {
        // Insert a 2nd EP in the same district/project with SAME assessed_loss.
        $epId2 = DB::table('affected_person')->insertGetId([
            'project_id' => $this->projectId,
            'ep_id' => 'EP'.rand(100000, 999999).'#0002',
            'name' => 'Geo Report Person 2',
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

        $epicadId2 = DB::table('epicad')->insertGetId([
            'affected_person_id' => $epId2,
            'issue_date' => '2025-06-01',
            'print_status' => 'NOT_PRINTED',
        ]);

        // Same assessed_loss = 200000.00 as the first EP's EC.
        DB::table('ec')->insert([
            'affected_person_id' => $epId2,
            'epicad_id' => $epicadId2,
            'ec_number' => 'EC-'.rand(100000, 999999),
            'assessment_date' => '2025-06-15',
            'total_assessed_loss' => '200000.00',
            'total_compensation' => '180000.00',
            'status' => 'APPROVED',
        ]);

        // Same grant_amount = 10000.00 as the first EP's allowance.
        DB::table('allowance')->insert([
            'affected_person_id' => $epId2,
            'vulnerability_rule_id' => $this->vulnerabilityRuleId,
            'is_eligible' => 1,
            'computed_annual_income' => '600000.00',
            'grant_amount' => '10000.00',
            'status' => 'APPROVED',
        ]);

        // R19 — District Summary
        $response = $this->fetchReport('district-summary', [
            'project_id' => $this->projectId,
        ]);

        $found = collect($response->json('data.rows'))
            ->firstWhere('district_id', $this->districtId);

        $this->assertEquals(2, $found['ep_count'], 'EP count should be 2');
        $this->assertEquals(2, $found['ec_count'], 'EC count should be 2');
        // 200000 + 200000 = 400000 (NOT 200000 via SUM(DISTINCT))
        $this->assertEquals('400000.00', $found['total_assessed'],
            'total_assessed must be SUM of both ECs (not deduped)');
        $this->assertEquals('360000.00', $found['total_compensation'],
            'total_compensation must be SUM of both ECs');
        // 10000 + 10000 = 20000 (NOT 10000 via SUM(DISTINCT))
        $this->assertEquals('20000.00', $found['total_grant'],
            'total_grant must be SUM of both allowances (not deduped)');
    }

    public function test_r20_duplicate_amounts_not_dropped(): void
    {
        // Reuse same setup as R19 test — add 2nd EP with same amounts.
        $epId2 = DB::table('affected_person')->insertGetId([
            'project_id' => $this->projectId,
            'ep_id' => 'EP'.rand(100000, 999999).'#0003',
            'name' => 'Geo Report Person 3',
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

        $epicadId2 = DB::table('epicad')->insertGetId([
            'affected_person_id' => $epId2,
            'issue_date' => '2025-06-01',
            'print_status' => 'NOT_PRINTED',
        ]);

        DB::table('ec')->insert([
            'affected_person_id' => $epId2,
            'epicad_id' => $epicadId2,
            'ec_number' => 'EC-'.rand(100000, 999999),
            'assessment_date' => '2025-06-15',
            'total_assessed_loss' => '200000.00',
            'total_compensation' => '180000.00',
            'status' => 'APPROVED',
        ]);

        DB::table('allowance')->insert([
            'affected_person_id' => $epId2,
            'vulnerability_rule_id' => $this->vulnerabilityRuleId,
            'is_eligible' => 1,
            'computed_annual_income' => '600000.00',
            'grant_amount' => '10000.00',
            'status' => 'APPROVED',
        ]);

        // R20 — Upazila Summary
        $response = $this->fetchReport('upazila-summary', [
            'district_id' => $this->districtId,
        ]);

        $found = collect($response->json('data.rows'))
            ->firstWhere('upazila_id', $this->upazilaId);

        $this->assertEquals(2, $found['ec_count']);
        $this->assertEquals('400000.00', $found['total_assessed'],
            'R20 total_assessed must be SUM of both ECs (not deduped)');
        $this->assertEquals('20000.00', $found['total_grant'],
            'R20 total_grant must be SUM of both allowances (not deduped)');
    }

    public function test_r21_duplicate_amounts_not_dropped(): void
    {
        // Add 2nd EP with same amounts to test R21's 5-way subquery approach.
        $epId2 = DB::table('affected_person')->insertGetId([
            'project_id' => $this->projectId,
            'ep_id' => 'EP'.rand(100000, 999999).'#0004',
            'name' => 'Geo Report Person 4',
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

        $epicadId2 = DB::table('epicad')->insertGetId([
            'affected_person_id' => $epId2,
            'issue_date' => '2025-06-01',
            'print_status' => 'NOT_PRINTED',
        ]);

        DB::table('ec')->insert([
            'affected_person_id' => $epId2,
            'epicad_id' => $epicadId2,
            'ec_number' => 'EC-'.rand(100000, 999999),
            'assessment_date' => '2025-06-15',
            'total_assessed_loss' => '200000.00',
            'total_compensation' => '180000.00',
            'status' => 'APPROVED',
        ]);

        DB::table('allowance')->insert([
            'affected_person_id' => $epId2,
            'vulnerability_rule_id' => $this->vulnerabilityRuleId,
            'is_eligible' => 1,
            'computed_annual_income' => '600000.00',
            'grant_amount' => '10000.00',
            'status' => 'APPROVED',
        ]);

        // R21 — Project Summary
        $response = $this->fetchReport('project-summary', [
            'project_id' => $this->projectId,
        ]);

        $rows = $response->json('data.rows');
        $this->assertCount(1, $rows);
        $found = $rows[0];

        $this->assertEquals(2, $found['ep_count'], 'EP count should be 2');
        $this->assertEquals(2, $found['ec_count'], 'EC count should be 2');
        $this->assertEquals('400000.00', $found['total_assessed'],
            'R21 total_assessed must be SUM of both ECs (not deduped)');
        $this->assertEquals('360000.00', $found['total_compensation'],
            'R21 total_compensation must be SUM of both ECs');
        $this->assertEquals('20000.00', $found['total_grant'],
            'R21 total_grant must be SUM of both allowances (not deduped)');
    }
}
