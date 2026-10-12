<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Report\ReportService;
use App\Services\Report\ReportType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 4B-7d-2 — EP/EC Report Queries (R01–R10).
 *
 * Each report is tested for:
 *   1. Envelope shape (success, message, data, meta)
 *   2. Column presence matching the declared columns() spec
 *   3. Correct data returned (row count, key field values)
 *   4. Filter functionality
 *   5. Empty result when no matching data
 *   6. Export integration (CSV download)
 *
 * All test data created via direct DB inserts.
 * Uses DatabaseTransactions — never RefreshDatabase.
 */
class ReportQueriesTest extends TestCase
{
    use DatabaseTransactions;

    private int $adminUserId;

    private string $adminToken;

    private int $projectId;

    private int $districtId;

    private int $upazilaId;

    // Seeded master IDs (deterministic from PHASE3-seed-master-data.sql).
    private int $genderId = 1;        // MALE

    private int $religionId = 1;      // ISLAM

    private int $occupationId = 1;    // FARMER

    private int $landStatusId = 1;    // TITLE

    private int $rcTypeId = 1;        // RESIDENTIAL

    private int $landTypeId = 1;      // AGRICULTURAL

    private int $damageTypeId = 1;    // PARTIAL

    private int $unitId = 1;          // DECIMAL

    private int $cropTypeId = 1;      // PADDY

    private int $cropSeasonId = 1;    // ROBHI

    private int $relationshipId = 4;  // SPOUSE

    private int $ownershipTypeId = 1; // OWNED

    private int $landUseTypeId = 1;   // AGRICULTURAL

    private int $landAreaUnitId = 1;  // DECIMAL

    // Test data IDs (populated in setUp).
    private int $epId;

    private int $epId2;

    private int $familyMemberId;

    private int $landId;

    private int $cropId;

    private int $epicadId;

    private int $ecId;

    private int $ecLineItemId;

    private int $landAssessmentId;

    private int $cropAssessmentId;

    protected function setUp(): void
    {
        parent::setUp();

        // ── Admin user (all permissions) ─────────────────────
        $this->adminUserId = DB::table('users')->insertGetId([
            'username' => 'rqr_admin_'.Str::random(6),
            'full_name' => 'Report Query Admin',
            'password_hash' => bcrypt('TestPass@12345'),
            'email' => Str::random(8).'@test.com',
            'mobile' => '017'.rand(10000000, 99999999),
            'is_active' => 1,
        ]);
        DB::table('user_role')->insert(['user_id' => $this->adminUserId, 'role_id' => 1]);
        $this->adminToken = $this->createToken($this->adminUserId);

        // ── Geography ────────────────────────────────────────
        $this->districtId = DB::table('district')->insertGetId([
            'code' => 'RPT'.rand(100, 999),
            'name' => 'Report District',
            'is_active' => 1,
        ]);
        $this->upazilaId = DB::table('upazila')->insertGetId([
            'district_id' => $this->districtId,
            'code' => 'RTU'.rand(100, 999),
            'name' => 'Report Upazila',
            'is_active' => 1,
        ]);

        // ── Project ──────────────────────────────────────────
        $this->projectId = DB::table('project')->insertGetId([
            'project_code' => 'RPT-'.rand(1000, 9999),
            'name' => 'Report Test Project',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'is_active' => 1,
        ]);

        // ── Affected Persons ─────────────────────────────────
        $this->epId = $this->createAffectedPerson([
            'ep_id' => 'EP'.rand(100000, 999999).'#0001',
            'name' => 'Report Person One',
            'father_name' => 'Father One',
            'mother_name' => 'Mother One',
            'nid_number' => (string) rand(1000000000, 9999999999),
            'mobile' => '017'.rand(10000000, 99999999),
            'status' => 'SUBMITTED',
        ]);

        $this->epId2 = $this->createAffectedPerson([
            'ep_id' => 'EP'.rand(100000, 999999).'#0002',
            'name' => 'Report Person Two',
            'father_name' => 'Father Two',
            'mother_name' => 'Mother Two',
            'nid_number' => (string) rand(1000000000, 9999999999),
            'mobile' => '017'.rand(10000000, 99999999),
            'status' => 'DRAFT',
            'gender_id' => 2, // FEMALE
        ]);

        // ── Family Member ────────────────────────────────────
        $this->familyMemberId = DB::table('family_member')->insertGetId([
            'affected_person_id' => $this->epId,
            'relationship_id' => $this->relationshipId,
            'gender_id' => 2,
            'age' => 30,
            'occupation_id' => 8,
            'is_dependent' => 1,
        ]);

        // ── Land ─────────────────────────────────────────────
        $this->landId = DB::table('land')->insertGetId([
            'affected_person_id' => $this->epId,
            'land_type_id' => $this->landTypeId,
            'land_status_id' => $this->landStatusId,
            'ownership_type_id' => $this->ownershipTypeId,
            'district_id' => $this->districtId,
            'upazila_id' => $this->upazilaId,
            'land_area' => '5.0000',
            'land_area_unit_id' => $this->landAreaUnitId,
            'land_use_type_id' => $this->landUseTypeId,
        ]);

        // ── Land Assessment ──────────────────────────────────
        $this->landAssessmentId = DB::table('land_assessment')->insertGetId([
            'land_id' => $this->landId,
            'damage_type_id' => $this->damageTypeId,
            'affected_area' => '3.0000',
            'unit_id' => $this->unitId,
            'rate_id' => $this->createLandRate(),
            'rate_per_unit' => '50000.00',
            'assessed_loss' => '150000.00',
        ]);

        // ── Crop ─────────────────────────────────────────────
        $this->cropId = DB::table('crop')->insertGetId([
            'land_id' => $this->landId,
            'crop_type_id' => $this->cropTypeId,
            'crop_season_id' => $this->cropSeasonId,
            'cultivated_area' => '4.0000',
            'area_unit_id' => $this->landAreaUnitId,
            'damage_percentage' => '50.00',
        ]);

        // ── Crop Assessment ──────────────────────────────────
        $this->cropAssessmentId = DB::table('crop_assessment')->insertGetId([
            'crop_id' => $this->cropId,
            'rate_id' => $this->createCropRate(),
            'cultivated_area' => '4.0000',
            'rate_per_unit' => '25000.00',
            'damage_percentage' => '50.00',
            'assessed_loss' => '50000.00',
        ]);

        // ── EPICAD ───────────────────────────────────────────
        $this->epicadId = DB::table('epicad')->insertGetId([
            'affected_person_id' => $this->epId,
            'issue_date' => '2025-06-01',
            'print_status' => 'NOT_PRINTED',
        ]);

        // ── EC ───────────────────────────────────────────────
        $this->ecId = DB::table('ec')->insertGetId([
            'affected_person_id' => $this->epId,
            'epicad_id' => $this->epicadId,
            'ec_number' => 'EC-'.rand(100000, 999999),
            'assessment_date' => '2025-06-15',
            'total_assessed_loss' => '200000.00',
            'total_compensation' => '180000.00',
            'status' => 'DRAFT',
        ]);

        // ── EC Line Item ─────────────────────────────────────
        $this->ecLineItemId = DB::table('ec_line_item')->insertGetId([
            'ec_id' => $this->ecId,
            'category' => 'LAND',
            'description' => 'Land assessment loss',
            'assessed_amount' => '150000.00',
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

    private function createAffectedPerson(array $overrides = []): int
    {
        return DB::table('affected_person')->insertGetId(array_merge([
            'project_id' => $this->projectId,
            'ep_id' => 'EP'.rand(100000, 999999),
            'name' => 'Test Person '.Str::random(5),
            'nid_number' => (string) rand(1000000000, 9999999999),
            'mobile' => '017'.rand(10000000, 99999999),
            'gender_id' => $this->genderId,
            'district_id' => $this->districtId,
            'upazila_id' => $this->upazilaId,
            'land_status_id' => $this->landStatusId,
            'rc_type_id' => $this->rcTypeId,
            'monthly_income' => '15000.00',
            'monthly_expense' => '10000.00',
            'status' => 'DRAFT',
            'is_household_head' => 1,
        ], $overrides));
    }

    private function createLandRate(): int
    {
        return DB::table('land_rate')->insertGetId([
            'land_type_id' => $this->landTypeId,
            'land_use_type_id' => $this->landUseTypeId,
            'unit_id' => $this->unitId,
            'rate_per_unit' => '50000.00',
            'effective_date' => '2025-01-01',
            'is_active' => 1,
        ]);
    }

    private function createCropRate(): int
    {
        return DB::table('crop_rate')->insertGetId([
            'crop_type_id' => $this->cropTypeId,
            'crop_season_id' => $this->cropSeasonId,
            'unit_id' => $this->landAreaUnitId,
            'rate_per_unit' => '25000.00',
            'effective_date' => '2025-01-01',
            'is_active' => 1,
        ]);
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

    // ═══════════════════════════════════════════════════════════════
    // R01 — EP REGISTER
    // ═══════════════════════════════════════════════════════════════

    public function test_r01_ep_register_envelope(): void
    {
        $response = $this->fetchReport('ep-register');

        $this->assertEnvelope($response, ReportType::EpRegister->value);
        $response->assertJsonPath('data.title', 'EP Register');
    }

    public function test_r01_ep_register_returns_data(): void
    {
        $response = $this->fetchReport('ep-register');

        $response->assertJsonPath('data.rows', fn (array $rows) => count($rows) >= 2);
        $response->assertJsonFragment(['ep_id' => DB::table('affected_person')->where('affected_person_id', $this->epId)->value('ep_id')]);
    }

    public function test_r01_ep_register_columns(): void
    {
        $response = $this->fetchReport('ep-register');

        $columns = collect($response->json('data.columns'));
        $keys = $columns->pluck('key')->toArray();

        $this->assertContains('ep_id', $keys);
        $this->assertContains('name', $keys);
        $this->assertContains('father_name', $keys);
        $this->assertContains('gender', $keys);
        $this->assertContains('district', $keys);
        $this->assertContains('upazila', $keys);
        $this->assertContains('status', $keys);
    }

    public function test_r01_ep_register_with_project_filter(): void
    {
        // Create EP in a different project.
        $otherProjectId = DB::table('project')->insertGetId([
            'project_code' => 'OTH-'.rand(1000, 9999),
            'name' => 'Other Project',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'is_active' => 1,
        ]);
        $this->createAffectedPerson([
            'project_id' => $otherProjectId,
            'ep_id' => 'EP'.rand(100000, 999999),
            'name' => 'Other Project Person',
        ]);

        $response = $this->fetchReport('ep-register', ['project_id' => $this->projectId]);

        $rows = $response->json('data.rows');
        // All returned rows should belong to this project, not the other one.
        foreach ($rows as $row) {
            $this->assertStringContainsString('EP', $row['ep_id'] ?? '');
        }
        $names = array_column($rows, 'name');
        $this->assertNotContains('Other Project Person', $names);
    }

    public function test_r01_ep_register_with_status_filter(): void
    {
        $response = $this->fetchReport('ep-register', ['status' => 'SUBMITTED']);

        $rows = $response->json('data.rows');
        $this->assertGreaterThanOrEqual(1, count($rows));
        foreach ($rows as $row) {
            $this->assertEquals('SUBMITTED', $row['status']);
        }
    }

    public function test_r01_ep_register_summary_count(): void
    {
        $response = $this->fetchReport('ep-register');

        $response->assertJsonPath('data.summary.total_count', fn ($count) => $count >= 2);
    }

    public function test_r01_ep_register_csv_export(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/ep-register/export?format=csv');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename=ep-register.csv');
    }

    // ═══════════════════════════════════════════════════════════════
    // R02 — EP STATUS SUMMARY
    // ═══════════════════════════════════════════════════════════════

    public function test_r02_ep_status_summary_envelope(): void
    {
        $response = $this->fetchReport('ep-status-summary');

        $this->assertEnvelope($response, ReportType::EpStatusSummary->value);
        $response->assertJsonPath('data.title', 'EP Status Summary');
    }

    public function test_r02_ep_status_summary_returns_groups(): void
    {
        $response = $this->fetchReport('ep-status-summary');

        $rows = $response->json('data.rows');
        $this->assertGreaterThanOrEqual(2, count($rows));

        // Should have both SUBMITTED and DRAFT.
        $statuses = array_column($rows, 'status');
        $this->assertContains('SUBMITTED', $statuses);
        $this->assertContains('DRAFT', $statuses);
    }

    public function test_r02_ep_status_summary_columns(): void
    {
        $response = $this->fetchReport('ep-status-summary');

        $columns = collect($response->json('data.columns'));
        $keys = $columns->pluck('key')->toArray();
        $this->assertContains('status', $keys);
        $this->assertContains('ep_count', $keys);
        $this->assertContains('percentage', $keys);
    }

    public function test_r02_ep_status_summary_totals(): void
    {
        $response = $this->fetchReport('ep-status-summary');

        $totalEp = collect($response->json('data.rows'))->sum('ep_count');
        $this->assertEquals(2, $totalEp);
    }

    public function test_r02_ep_status_summary_csv_export(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/ep-status-summary/export?format=csv');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }

    // ═══════════════════════════════════════════════════════════════
    // R03 — EP DEMOGRAPHICS
    // ═══════════════════════════════════════════════════════════════

    public function test_r03_ep_demographics_envelope(): void
    {
        $response = $this->fetchReport('ep-demographics');

        $this->assertEnvelope($response, ReportType::EpDemographics->value);
        $response->assertJsonPath('data.title', 'EP Demographic Summary');
    }

    public function test_r03_ep_demographics_has_dimensions(): void
    {
        $response = $this->fetchReport('ep-demographics');

        $rows = $response->json('data.rows');
        $this->assertGreaterThanOrEqual(2, count($rows));

        $dimensions = array_unique(array_column($rows, 'dimension'));
        $this->assertContains('Gender', $dimensions);
    }

    public function test_r03_ep_demographics_columns(): void
    {
        $response = $this->fetchReport('ep-demographics');

        $columns = collect($response->json('data.columns'));
        $keys = $columns->pluck('key')->toArray();
        $this->assertContains('dimension', $keys);
        $this->assertContains('dimension_value', $keys);
        $this->assertContains('ep_count', $keys);
    }

    public function test_r03_ep_demographics_total(): void
    {
        $response = $this->fetchReport('ep-demographics');

        $response->assertJsonPath('data.summary.total_ep', 2);
    }

    // ═══════════════════════════════════════════════════════════════
    // R04 — FAMILY MEMBER REGISTER
    // ═══════════════════════════════════════════════════════════════

    public function test_r04_family_register_envelope(): void
    {
        $response = $this->fetchReport('family-register');

        $this->assertEnvelope($response, ReportType::FamilyRegister->value);
        $response->assertJsonPath('data.title', 'Family Member Register');
    }

    public function test_r04_family_register_returns_data(): void
    {
        $response = $this->fetchReport('family-register');

        $rows = $response->json('data.rows');
        $this->assertGreaterThanOrEqual(1, count($rows));

        // Verify the member is linked to the correct EP.
        $first = collect($rows)->firstWhere('family_member_id', $this->familyMemberId);
        $this->assertNotNull($first);
        $this->assertEquals('Spouse', $first['relationship']);
        $this->assertEquals(30, $first['age']);
    }

    public function test_r04_family_register_columns(): void
    {
        $response = $this->fetchReport('family-register');

        $columns = collect($response->json('data.columns'));
        $keys = $columns->pluck('key')->toArray();
        $this->assertContains('family_member_id', $keys);
        $this->assertContains('ep_id', $keys);
        $this->assertContains('relationship', $keys);
        $this->assertContains('gender', $keys);
        $this->assertContains('age', $keys);
        $this->assertContains('occupation', $keys);
        $this->assertContains('is_dependent', $keys);
    }

    public function test_r04_family_register_summary(): void
    {
        $response = $this->fetchReport('family-register');

        $response->assertJsonPath('data.summary.total_members', 1);
    }

    // ═══════════════════════════════════════════════════════════════
    // R05 — EPICAD LIST
    // ═══════════════════════════════════════════════════════════════

    public function test_r05_epicad_list_envelope(): void
    {
        $response = $this->fetchReport('epicad-list');

        $this->assertEnvelope($response, ReportType::EpicadList->value);
        $response->assertJsonPath('data.title', 'EPICAD List');
    }

    public function test_r05_epicad_list_returns_data(): void
    {
        $response = $this->fetchReport('epicad-list');

        $rows = $response->json('data.rows');
        $this->assertGreaterThanOrEqual(1, count($rows));
        $response->assertJsonFragment([
            'epicad_id' => $this->epicadId,
            'print_status' => 'NOT_PRINTED',
        ]);
    }

    public function test_r05_epicad_list_columns(): void
    {
        $response = $this->fetchReport('epicad-list');

        $columns = collect($response->json('data.columns'));
        $keys = $columns->pluck('key')->toArray();
        $this->assertContains('epicad_id', $keys);
        $this->assertContains('ep_id', $keys);
        $this->assertContains('name', $keys);
        $this->assertContains('issue_date', $keys);
        $this->assertContains('print_status', $keys);
    }

    public function test_r05_epicad_list_with_print_status_filter(): void
    {
        $response = $this->fetchReport('epicad-list', ['print_status' => 'PRINTED']);

        $rows = $response->json('data.rows');
        $this->assertEmpty($rows); // We only have NOT_PRINTED.
    }

    // ═══════════════════════════════════════════════════════════════
    // R06 — EC REGISTER
    // ═══════════════════════════════════════════════════════════════

    public function test_r06_ec_register_envelope(): void
    {
        $response = $this->fetchReport('ec-register');

        $this->assertEnvelope($response, ReportType::EcRegister->value);
        $response->assertJsonPath('data.title', 'EC Register');
    }

    public function test_r06_ec_register_returns_data(): void
    {
        $response = $this->fetchReport('ec-register');

        $rows = $response->json('data.rows');
        $this->assertGreaterThanOrEqual(1, count($rows));

        $first = collect($rows)->firstWhere('ec_id', $this->ecId);
        $this->assertNotNull($first);
        $this->assertEquals('DRAFT', $first['status']);
        $this->assertEquals('200000.00', $first['total_assessed_loss']);
        $this->assertEquals('180000.00', $first['total_compensation']);
    }

    public function test_r06_ec_register_columns(): void
    {
        $response = $this->fetchReport('ec-register');

        $columns = collect($response->json('data.columns'));
        $keys = $columns->pluck('key')->toArray();
        $this->assertContains('ec_id', $keys);
        $this->assertContains('ec_number', $keys);
        $this->assertContains('ep_id', $keys);
        $this->assertContains('assessment_date', $keys);
        $this->assertContains('total_assessed_loss', $keys);
        $this->assertContains('total_compensation', $keys);
        $this->assertContains('status', $keys);
    }

    public function test_r06_ec_register_summary(): void
    {
        $response = $this->fetchReport('ec-register');

        $summary = $response->json('data.summary');
        $this->assertGreaterThanOrEqual(1, $summary['total_ec']);
        $this->assertEquals('200000.00', $summary['total_assessed']);
        $this->assertEquals('180000.00', $summary['total_compensation']);
    }

    public function test_r06_ec_register_with_status_filter(): void
    {
        $response = $this->fetchReport('ec-register', ['status' => 'DRAFT']);

        $rows = $response->json('data.rows');
        $this->assertGreaterThanOrEqual(1, count($rows));
        foreach ($rows as $row) {
            $this->assertEquals('DRAFT', $row['status']);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // R07 — EC STATUS SUMMARY
    // ═══════════════════════════════════════════════════════════════

    public function test_r07_ec_status_summary_envelope(): void
    {
        $response = $this->fetchReport('ec-status-summary');

        $this->assertEnvelope($response, ReportType::EcStatusSummary->value);
        $response->assertJsonPath('data.title', 'EC Status Summary');
    }

    public function test_r07_ec_status_summary_returns_groups(): void
    {
        $response = $this->fetchReport('ec-status-summary');

        $rows = $response->json('data.rows');
        $this->assertGreaterThanOrEqual(1, count($rows));

        $statuses = array_column($rows, 'status');
        $this->assertContains('DRAFT', $statuses);
    }

    public function test_r07_ec_status_summary_columns(): void
    {
        $response = $this->fetchReport('ec-status-summary');

        $columns = collect($response->json('data.columns'));
        $keys = $columns->pluck('key')->toArray();
        $this->assertContains('status', $keys);
        $this->assertContains('ec_count', $keys);
        $this->assertContains('percentage', $keys);
        $this->assertContains('total_assessed', $keys);
        $this->assertContains('total_compensation', $keys);
    }

    public function test_r07_ec_status_summary_totals(): void
    {
        $response = $this->fetchReport('ec-status-summary');

        $totalEc = collect($response->json('data.rows'))->sum('ec_count');
        $this->assertEquals(1, $totalEc);
    }

    // ═══════════════════════════════════════════════════════════════
    // R08 — ASSESSMENT SUMMARY
    // ═══════════════════════════════════════════════════════════════

    public function test_r08_assessment_summary_envelope(): void
    {
        $response = $this->fetchReport('assessment-summary');

        $this->assertEnvelope($response, ReportType::AssessmentSummary->value);
        $response->assertJsonPath('data.title', 'Assessment Summary by Category');
    }

    public function test_r08_assessment_summary_returns_data(): void
    {
        $response = $this->fetchReport('assessment-summary');

        $rows = $response->json('data.rows');
        $this->assertGreaterThanOrEqual(1, count($rows));

        $landRow = collect($rows)->firstWhere('category', 'LAND');
        $this->assertNotNull($landRow);
        $this->assertEquals('150000.00', $landRow['total_assessed']);
    }

    public function test_r08_assessment_summary_columns(): void
    {
        $response = $this->fetchReport('assessment-summary');

        $columns = collect($response->json('data.columns'));
        $keys = $columns->pluck('key')->toArray();
        $this->assertContains('category', $keys);
        $this->assertContains('ec_count', $keys);
        $this->assertContains('ep_count', $keys);
        $this->assertContains('total_assessed', $keys);
        $this->assertContains('percentage', $keys);
    }

    // ═══════════════════════════════════════════════════════════════
    // R09 — LAND ASSESSMENT DETAIL
    // ═══════════════════════════════════════════════════════════════

    public function test_r09_land_assessment_envelope(): void
    {
        $response = $this->fetchReport('land-assessment');

        $this->assertEnvelope($response, ReportType::LandAssessment->value);
        $response->assertJsonPath('data.title', 'Land Assessment Detail');
    }

    public function test_r09_land_assessment_returns_data(): void
    {
        $response = $this->fetchReport('land-assessment');

        $rows = $response->json('data.rows');
        $this->assertGreaterThanOrEqual(1, count($rows));

        $first = collect($rows)->firstWhere('land_assessment_id', $this->landAssessmentId);
        $this->assertNotNull($first);
        $this->assertEquals('Agricultural', $first['land_type']);
        $this->assertEquals('Partial', $first['damage_type']);
        $this->assertEquals('5.0000', $first['land_area']);
        $this->assertEquals('150000.00', $first['assessed_loss']);
    }

    public function test_r09_land_assessment_columns(): void
    {
        $response = $this->fetchReport('land-assessment');

        $columns = collect($response->json('data.columns'));
        $keys = $columns->pluck('key')->toArray();
        $this->assertContains('land_assessment_id', $keys);
        $this->assertContains('ep_id', $keys);
        $this->assertContains('land_type', $keys);
        $this->assertContains('land_area', $keys);
        $this->assertContains('damage_type', $keys);
        $this->assertContains('affected_area', $keys);
        $this->assertContains('rate_per_unit', $keys);
        $this->assertContains('assessed_loss', $keys);
    }

    public function test_r09_land_assessment_summary(): void
    {
        $response = $this->fetchReport('land-assessment');

        $summary = $response->json('data.summary');
        $this->assertGreaterThanOrEqual(1, $summary['total_assessments']);
        $this->assertEquals('150000.00', $summary['total_assessed']);
    }

    // ═══════════════════════════════════════════════════════════════
    // R10 — CROP ASSESSMENT DETAIL
    // ═══════════════════════════════════════════════════════════════

    public function test_r10_crop_assessment_envelope(): void
    {
        $response = $this->fetchReport('crop-assessment');

        $this->assertEnvelope($response, ReportType::CropAssessment->value);
        $response->assertJsonPath('data.title', 'Crop Assessment Detail');
    }

    public function test_r10_crop_assessment_returns_data(): void
    {
        $response = $this->fetchReport('crop-assessment');

        $rows = $response->json('data.rows');
        $this->assertGreaterThanOrEqual(1, count($rows));

        $first = collect($rows)->firstWhere('crop_assessment_id', $this->cropAssessmentId);
        $this->assertNotNull($first);
        $this->assertEquals('Paddy (Rice)', $first['crop_type']);
        $this->assertEquals('Robi', $first['season']);
        $this->assertEquals('50.00', $first['damage_percentage']);
        $this->assertEquals('50000.00', $first['assessed_loss']);
    }

    public function test_r10_crop_assessment_columns(): void
    {
        $response = $this->fetchReport('crop-assessment');

        $columns = collect($response->json('data.columns'));
        $keys = $columns->pluck('key')->toArray();
        $this->assertContains('crop_assessment_id', $keys);
        $this->assertContains('ep_id', $keys);
        $this->assertContains('crop_type', $keys);
        $this->assertContains('season', $keys);
        $this->assertContains('cultivated_area', $keys);
        $this->assertContains('damage_percentage', $keys);
        $this->assertContains('rate_per_unit', $keys);
        $this->assertContains('assessed_loss', $keys);
    }

    public function test_r10_crop_assessment_summary(): void
    {
        $response = $this->fetchReport('crop-assessment');

        $summary = $response->json('data.summary');
        $this->assertGreaterThanOrEqual(1, $summary['total_assessments']);
        $this->assertEquals('50000.00', $summary['total_assessed']);
    }

    // ═══════════════════════════════════════════════════════════════
    // CROSS-CUTTING: READ-ONLY SAFETY
    // ═══════════════════════════════════════════════════════════════

    public function test_all_reports_are_read_only(): void
    {
        $counts = [
            'affected_person' => DB::table('affected_person')->count(),
            'ec' => DB::table('ec')->count(),
            'land_assessment' => DB::table('land_assessment')->count(),
            'crop_assessment' => DB::table('crop_assessment')->count(),
        ];

        // Hit all 10 reports.
        $types = [
            'ep-register', 'ep-status-summary', 'ep-demographics',
            'family-register', 'epicad-list', 'ec-register',
            'ec-status-summary', 'assessment-summary',
            'land-assessment', 'crop-assessment',
        ];

        foreach ($types as $type) {
            $this->fetchReport($type);
        }

        // Counts should not have changed.
        $this->assertEquals($counts['affected_person'], DB::table('affected_person')->count());
        $this->assertEquals($counts['ec'], DB::table('ec')->count());
        $this->assertEquals($counts['land_assessment'], DB::table('land_assessment')->count());
        $this->assertEquals($counts['crop_assessment'], DB::table('crop_assessment')->count());
    }

    // ═══════════════════════════════════════════════════════════════
    // CROSS-CUTTING: EMPTY RESULTS
    // ═══════════════════════════════════════════════════════════════

    public function test_reports_return_empty_with_no_data(): void
    {
        // Create a project with no EPs.
        $emptyProjectId = DB::table('project')->insertGetId([
            'project_code' => 'EMPTY-'.rand(1000, 9999),
            'name' => 'Empty Project',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'is_active' => 1,
        ]);

        $response = $this->fetchReport('ep-register', ['project_id' => $emptyProjectId]);

        $response->assertOk();
        $response->assertJsonPath('data.rows', []);
    }

    // ═══════════════════════════════════════════════════════════════
    // CROSS-CUTTING: IMPLEMENTED CHECK
    // ═══════════════════════════════════════════════════════════════

    public function test_r01_to_r10_are_now_implemented(): void
    {
        $service = app(ReportService::class);

        $implemented = [
            ReportType::EpRegister,
            ReportType::EpStatusSummary,
            ReportType::EpDemographics,
            ReportType::FamilyRegister,
            ReportType::EpicadList,
            ReportType::EcRegister,
            ReportType::EcStatusSummary,
            ReportType::AssessmentSummary,
            ReportType::LandAssessment,
            ReportType::CropAssessment,
        ];

        foreach ($implemented as $type) {
            $this->assertTrue(
                $service->isImplemented($type),
                "Report type {$type->value} should be implemented",
            );
        }
    }
}
