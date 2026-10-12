<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Report\Export\CsvExport;
use App\Services\Report\ReportQuery\NullReportQuery;
use App\Services\Report\ReportService;
use App\Services\Report\ReportType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4B-7d-1 — Report foundation tests.
 *
 * Covers:
 *   - Unknown report type → 404
 *   - Unauthenticated → 401
 *   - Missing report.view → 403
 *   - Missing report.export → 403
 *   - Valid report → correct envelope shape
 *   - NullReportQuery placeholder returns empty rows
 *   - Filters accepted and validated
 *   - Pagination limits enforced
 *   - CSV export download
 *   - Export format validation
 *   - No database mutations during report generation
 *
 * All auth test data created via direct DB inserts (session contamination avoidance).
 */
class ReportFoundationTest extends TestCase
{
    use DatabaseTransactions;

    private int $adminUserId;

    private string $adminToken;

    private int $viewerUserId;

    private string $viewerToken;

    protected function setUp(): void
    {
        parent::setUp();

        // Admin user with all permissions (including report.view + report.export).
        $this->adminUserId = DB::table('users')->insertGetId([
            'username' => 'rpt_admin_'.Str::random(6),
            'full_name' => 'Report Admin',
            'password_hash' => bcrypt('TestPass@12345'),
            'email' => Str::random(8).'@test.com',
            'mobile' => '017'.rand(10000000, 99999999),
            'is_active' => 1,
        ]);
        DB::table('user_role')->insert(['user_id' => $this->adminUserId, 'role_id' => 1]); // ADMIN
        $this->adminToken = $this->actingAsUser($this->adminUserId);

        // Viewer user: report.view but NOT report.export (role 6 = REPORT_VIEWER).
        $this->viewerUserId = DB::table('users')->insertGetId([
            'username' => 'rpt_viewer_'.Str::random(6),
            'full_name' => 'Report Viewer',
            'password_hash' => bcrypt('TestPass@12345'),
            'email' => Str::random(8).'@test.com',
            'mobile' => '017'.rand(10000000, 99999999),
            'is_active' => 1,
        ]);
        DB::table('user_role')->insert(['user_id' => $this->viewerUserId, 'role_id' => 6]); // REPORT_VIEWER
        $this->viewerToken = $this->actingAsUser($this->viewerUserId);
    }

    /**
     * Create a Sanctum personal access token for the given user.
     */
    private function actingAsUser(int $userId): string
    {
        $user = User::findOrFail($userId);

        return $user->createToken('test-token')->plainTextToken;
    }

    // ═══════════════════════════════════════════════════════════════
    // AUTHENTICATION
    // ═══════════════════════════════════════════════════════════════

    public function test_unauthenticated_index_returns_401(): void
    {
        $response = $this->getJson('/api/v1/reports/ep-register');

        $response->assertStatus(401);
    }

    public function test_unauthenticated_export_returns_401(): void
    {
        $response = $this->getJson('/api/v1/reports/ep-register/export?format=csv');

        $response->assertStatus(401);
    }

    // ═══════════════════════════════════════════════════════════════
    // AUTHORIZATION
    // ═══════════════════════════════════════════════════════════════

    public function test_missing_report_view_permission_returns_403(): void
    {
        // Create a user with NO report permissions.
        $noPermUserId = DB::table('users')->insertGetId([
            'username' => 'rpt_noperm_'.Str::random(6),
            'full_name' => 'No Permission',
            'password_hash' => bcrypt('TestPass@12345'),
            'email' => Str::random(8).'@test.com',
            'mobile' => '017'.rand(10000000, 99999999),
            'is_active' => 1,
        ]);
        // role 2 = SURVEYOR (has no report.view)
        DB::table('user_role')->insert(['user_id' => $noPermUserId, 'role_id' => 2]);
        $token = $this->actingAsUser($noPermUserId);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/reports/ep-register');

        $response->assertStatus(403);
    }

    public function test_missing_report_export_permission_returns_403(): void
    {
        // APPROVER (role_id=4) has report.view but NOT report.export.
        $approverUserId = DB::table('users')->insertGetId([
            'username' => 'rpt_appr_'.Str::random(6),
            'full_name' => 'Approver No Export',
            'password_hash' => bcrypt('TestPass@12345'),
            'email' => Str::random(8).'@test.com',
            'mobile' => '017'.rand(10000000, 99999999),
            'is_active' => 1,
        ]);
        DB::table('user_role')->insert(['user_id' => $approverUserId, 'role_id' => 4]); // APPROVER
        $token = $this->actingAsUser($approverUserId);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->get('/api/v1/reports/ep-register/export?format=csv');

        $response->assertStatus(403);
    }

    public function test_admin_can_access_index(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/ep-register');

        $response->assertOk();
    }

    public function test_viewer_can_access_index(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->viewerToken}")
            ->getJson('/api/v1/reports/ep-register');

        $response->assertOk();
    }

    public function test_admin_can_access_export(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/ep-register/export?format=csv');

        $response->assertOk();
    }

    // ═══════════════════════════════════════════════════════════════
    // UNKNOWN REPORT TYPE
    // ═══════════════════════════════════════════════════════════════

    public function test_unknown_report_type_returns_404(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/nonexistent-report');

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.code.0', 'REPORT_TYPE_NOT_FOUND');
    }

    public function test_unknown_report_type_export_returns_404(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/nonexistent-report/export?format=csv');

        $response->assertStatus(404);
    }

    // ═══════════════════════════════════════════════════════════════
    // RESPONSE ENVELOPE (NullReportQuery placeholder)
    // ═══════════════════════════════════════════════════════════════

    public function test_valid_report_returns_correct_envelope(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/ep-register');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'title',
                    'columns',
                    'rows',
                ],
                'meta' => [
                    'type',
                    'filters_applied',
                    'total_rows',
                ],
            ]);
    }

    public function test_report_meta_contains_type_slug(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/ep-register');

        $response->assertOk()
            ->assertJsonPath('meta.type', 'ep-register');
    }

    public function test_report_data_title_matches_type_label(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/ep-register');

        $response->assertOk()
            ->assertJsonPath('data.title', 'EP Register');
    }

    public function test_all_23_types_are_implemented(): void
    {
        // Phase 4B-7d-4 completed: all 23 report types have concrete query classes.
        // Verify that every ReportType enum case resolves to a non-null query.
        $service = app(ReportService::class);

        foreach (ReportType::cases() as $type) {
            $query = $service->resolveQuery($type, []);
            $this->assertNotInstanceOf(
                NullReportQuery::class,
                $query,
                "Report type {$type->value} still resolves to NullReportQuery",
            );
        }
    }

    public function test_all_registered_types_return_valid_envelope(): void
    {
        foreach (ReportType::cases() as $type) {
            $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
                ->getJson("/api/v1/reports/{$type->value}");

            $response->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('data.title', $type->label())
                ->assertJsonPath('meta.type', $type->value);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // FILTERS
    // ═══════════════════════════════════════════════════════════════

    public function test_filters_are_passed_through_in_meta(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/ep-register?project_id=1&district_id=2&status=APPROVED');

        $response->assertOk()
            ->assertJsonFragment(['project_id' => '1'])
            ->assertJsonFragment(['district_id' => '2'])
            ->assertJsonFragment(['status' => 'APPROVED']);
    }

    public function test_invalid_filter_values_are_rejected(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/ep-register?project_id=abc');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['project_id']);
    }

    public function test_invalid_category_value_is_rejected(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/ep-register?category=INVALID');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['category']);
    }

    public function test_valid_category_values_are_accepted(): void
    {
        foreach (['LAND', 'CROP', 'INFRASTRUCTURE', 'TREE', 'BUSINESS', 'WORKER', 'OTHER'] as $cat) {
            $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
                ->getJson("/api/v1/reports/ep-register?category={$cat}");

            $response->assertOk();
        }
    }

    public function test_to_date_must_be_after_from_date(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/ep-register?from_date=2026-01-10&to_date=2026-01-01');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['to_date']);
    }

    public function test_per_page_max_is_100(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/ep-register?per_page=200');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
    }

    // ═══════════════════════════════════════════════════════════════
    // CSV EXPORT
    // ═══════════════════════════════════════════════════════════════

    public function test_csv_export_returns_csv_content(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->get('/api/v1/reports/ep-register/export?format=csv');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8')
            ->assertHeaderContains('Content-Disposition', 'ep-register.csv');
    }

    public function test_csv_export_has_header_row(): void
    {
        // Test CsvExport directly (streamed responses don't expose content via getContent()).
        $dataset = [
            'title' => 'Test Report',
            'columns' => [
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'count', 'label' => 'Count', 'type' => 'integer'],
            ],
            'rows' => [
                ['name' => 'Alice', 'count' => 5],
                ['name' => 'Bob', 'count' => 3],
            ],
        ];

        $csv = app(CsvExport::class)->generate($dataset);
        $lines = array_filter(explode("\n", trim($csv)));

        // First line should be the header row.
        $this->assertNotEmpty($lines);
        $firstLine = reset($lines);
        $this->assertStringContainsString('Name', $firstLine);
        $this->assertStringContainsString('Count', $firstLine);

        // Second line should be Alice's data.
        $secondLine = next($lines);
        $this->assertStringContainsString('Alice', $secondLine);
        $this->assertStringContainsString('5', $secondLine);

        // Third line should be Bob's data.
        $thirdLine = next($lines);
        $this->assertStringContainsString('Bob', $thirdLine);
        $this->assertStringContainsString('3', $thirdLine);
    }

    public function test_invalid_export_format_returns_422(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/ep-register/export?format=invalid');

        $response->assertStatus(422);
    }

    public function test_xlsx_export_returns_501_not_yet_available(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->get('/api/v1/reports/ep-register/export?format=xlsx');

        $response->assertStatus(501)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.code.0', 'EXPORT_NOT_AVAILABLE');
    }

    public function test_pdf_export_returns_501_not_yet_available(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->get('/api/v1/reports/ep-register/export?format=pdf');

        $response->assertStatus(501)
            ->assertJsonPath('success', false)
            ->assertJsonPath('errors.code.0', 'EXPORT_NOT_AVAILABLE');
    }

    public function test_default_export_format_is_csv(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->get('/api/v1/reports/ep-register/export');

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    }

    // ═══════════════════════════════════════════════════════════════
    // READ-ONLY INVARIANT
    // ═══════════════════════════════════════════════════════════════

    public function test_report_index_does_not_mutate_database(): void
    {
        // Count affected_person rows before.
        $countBefore = DB::table('affected_person')->count();

        $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/ep-register');

        // Count should be unchanged.
        $this->assertEquals($countBefore, DB::table('affected_person')->count());
    }

    public function test_report_export_does_not_mutate_database(): void
    {
        $countBefore = DB::table('affected_person')->count();

        $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->get('/api/v1/reports/ep-register/export?format=csv');

        $this->assertEquals($countBefore, DB::table('affected_person')->count());
    }

    // ═══════════════════════════════════════════════════════════════
    // ROUTE ORDERING (reports/{type} must not shadow other routes)
    // ═══════════════════════════════════════════════════════════════

    public function test_health_endpoint_still_works(): void
    {
        // Verify report routes don't interfere with existing routes.
        $response = $this->getJson('/api/v1/health');

        $response->assertOk();
    }

    public function test_map_surveys_still_works(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/map/surveys?bbox=88.0,21.5,92.5,26.7');

        $response->assertOk();
    }
}
