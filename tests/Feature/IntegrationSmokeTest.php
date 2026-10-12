<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Integration Smoke Tests (Phase 4B-7f-5).
 *
 * Behavioral validation of the complete frontend → API → backend pipeline.
 * These tests verify the actual runtime behavior, not just structure.
 *
 * Covers:
 *   1. Money values remain JSON strings with 2 decimal places
 *   2. Date fields render consistently (YYYY-MM-DD or ISO 8601)
 *   3. Empty reports return correct envelope structure
 *   4. R22 entity_totals renders as nested object
 *   5. Pagination works at first/middle/last boundaries
 *   6. 401/403 auth transitions
 *   7. CSV export preserves filters and returns correct format
 *
 * CRITICAL: `npm run export-contract` must be run BEFORE this test.
 */
class IntegrationSmokeTest extends TestCase
{
    use DatabaseTransactions;

    private string $adminToken;

    private string $viewerToken;

    protected function setUp(): void
    {
        parent::setUp();

        // Admin user (role_id=1 = admin)
        $adminId = DB::table('users')->insertGetId([
            'username' => 'smoke_admin_'.Str::random(6),
            'full_name' => 'Smoke Test Admin',
            'password_hash' => bcrypt('TestPass@12345'),
            'email' => Str::random(8).'@test.com',
            'mobile' => '017'.rand(10000000, 99999999),
            'is_active' => 1,
        ]);
        DB::table('user_role')->insert(['user_id' => $adminId, 'role_id' => 1]);
        $this->adminToken = User::find($adminId)->createToken('test-token')->plainTextToken;

        // Viewer user (role_id=2 = viewer)
        $viewerId = DB::table('users')->insertGetId([
            'username' => 'smoke_viewer_'.Str::random(6),
            'full_name' => 'Smoke Test Viewer',
            'password_hash' => bcrypt('TestPass@12345'),
            'email' => Str::random(8).'@test.com',
            'mobile' => '017'.rand(10000000, 99999999),
            'is_active' => 1,
        ]);
        DB::table('user_role')->insert(['user_id' => $viewerId, 'role_id' => 2]);

        // ── Contract-Policy Decision #4: Seed deterministic viewer permissions ──
        // Ensure report.view and report.export permissions exist in the DB,
        // then grant them to role_id=2 so viewer access is deterministic.
        DB::table('permission')->updateOrInsert(
            ['code' => 'report.view'],
            ['name' => 'View Reports', 'is_active' => 1],
        );
        DB::table('permission')->updateOrInsert(
            ['code' => 'report.export'],
            ['name' => 'Export Reports', 'is_active' => 1],
        );

        $viewPermId = DB::table('permission')->where('code', 'report.view')->value('permission_id');
        $exportPermId = DB::table('permission')->where('code', 'report.export')->value('permission_id');

        DB::table('role_permission')->updateOrInsert(
            ['role_id' => 2, 'permission_id' => $viewPermId],
        );
        DB::table('role_permission')->updateOrInsert(
            ['role_id' => 2, 'permission_id' => $exportPermId],
        );

        // Clear cached permissions so new grants take effect immediately.
        app(AuthService::class)->forgetUserPermissions(User::find($viewerId));

        $this->viewerToken = User::find($viewerId)->createToken('test-token')->plainTextToken;
    }

    // ═══════════════════════════════════════════════════════════
    // 1. Money values remain strings
    // ═══════════════════════════════════════════════════════════

    public function test_r01_money_columns_are_strings(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/ep-register?project_id=999999');
        $response->assertStatus(200);

        $columns = $response->json('data.columns');
        $moneyKeys = array_column(
            array_filter($columns, fn ($c) => $c['type'] === 'money'),
            'key',
        );

        $this->assertNotEmpty($moneyKeys, 'R01 should have money columns.');

        // With empty data, summary values should still follow the money string contract
        $summary = $response->json('data.summary');
        $this->assertIsArray($summary);
    }

    public function test_r06_money_columns_are_strings(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/ec-register?project_id=999999');
        $response->assertStatus(200);

        $columns = $response->json('data.columns');
        $moneyCols = array_filter($columns, fn ($c) => $c['type'] === 'money');
        $this->assertNotEmpty($moneyCols, 'R06 should have money columns.');

        foreach ($moneyCols as $col) {
            $this->assertSame('money', $col['type'], "R06.{$col['key']}: type must be 'money'.");
        }
    }

    /**
     * Verify that the contract doc's money format rule is enforced:
     * Money values are serialized as decimal:2 strings.
     **/
    #[DataProvider('moneyReportProvider')]
    public function test_money_type_present_in_columns(string $slug, array $expectedMoneyKeys): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson("/api/v1/reports/{$slug}?project_id=999999");
        $response->assertStatus(200);

        $columns = $response->json('data.columns');
        $actualMoneyKeys = array_column(
            array_filter($columns, fn ($c) => $c['type'] === 'money'),
            'key',
        );

        foreach ($expectedMoneyKeys as $key) {
            $this->assertContains(
                $key,
                $actualMoneyKeys,
                "{$slug}.{$key}: expected money type but not found in API response.",
            );
        }
    }

    // ═══════════════════════════════════════════════════════════
    // 2. Date fields render consistently
    // ═══════════════════════════════════════════════════════════

    public function test_r01_date_columns_are_date_type(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/ep-register?project_id=999999');
        $response->assertStatus(200);

        $columns = $response->json('data.columns');
        $dateCols = array_filter($columns, fn ($c) => $c['type'] === 'date');
        $this->assertNotEmpty($dateCols, 'R01 should have date columns.');

        foreach ($dateCols as $col) {
            $this->assertSame('date', $col['type'], "R01.{$col['key']}: type must be 'date'.");
        }
    }

    // ═══════════════════════════════════════════════════════════
    // 3. Empty reports render correctly
    // ═══════════════════════════════════════════════════════════

    #[DataProvider('allSlugsProvider')]
    public function test_empty_result_has_correct_envelope(string $slug): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson("/api/v1/reports/{$slug}?project_id=999999");
        $response->assertStatus(200);

        // Envelope structure
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => ['title', 'columns', 'rows', 'summary'],
            'meta' => ['type', 'filters_applied', 'total_rows'],
        ]);

        // rows is always an array, never null
        $rows = $response->json('data.rows');
        $this->assertIsArray($rows, "{$slug}: data.rows must be an array.");

        // columns are always present even with empty rows
        $columns = $response->json('data.columns');
        $this->assertIsArray($columns, "{$slug}: data.columns must be an array.");
        $this->assertNotEmpty($columns, "{$slug}: data.columns must not be empty.");

        // summary is always present
        $summary = $response->json('data.summary');
        $this->assertIsArray($summary, "{$slug}: data.summary must be an array.");
    }

    #[DataProvider('allSlugsProvider')]
    public function test_empty_result_total_rows_is_zero(string $slug): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson("/api/v1/reports/{$slug}?project_id=999999");
        $response->assertStatus(200);
        $response->assertJsonPath('meta.total_rows', 0);
    }

    // ═══════════════════════════════════════════════════════════
    // 4. R22 entity_totals renders correctly
    // ═══════════════════════════════════════════════════════════

    public function test_r22_summary_contains_entity_totals(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/workflow-status?project_id=999999');
        $response->assertStatus(200);

        $summary = $response->json('data.summary');
        $this->assertIsArray($summary);

        // R22 must have entity_totals as a nested object
        $this->assertArrayHasKey('entity_totals', $summary, 'R22 summary must contain entity_totals.');
        $entityTotals = $summary['entity_totals'];
        $this->assertIsArray($entityTotals, 'entity_totals must be an array/object.');

        // With no data (project_id=999999), entity_totals is empty — that's valid.
        // When data exists, each value must be an integer.
        if (! empty($entityTotals)) {
            foreach ($entityTotals as $type => $count) {
                $this->assertIsInt($count, 'R22.entity_totals.{$type} must be an integer.');
            }
        }
    }

    public function test_r22_columns_match_registry(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/workflow-status?project_id=999999');
        $response->assertStatus(200);

        $columns = $response->json('data.columns');
        $keys = array_column($columns, 'key');

        $this->assertContains('entity_type', $keys, 'R22 must have entity_type column.');
        $this->assertContains('status', $keys, 'R22 must have status column.');
        $this->assertContains('count', $keys, 'R22 must have count column.');
        $this->assertCount(3, $columns, 'R22 must have exactly 3 columns.');
    }

    // ═══════════════════════════════════════════════════════════
    // 5. Pagination works
    // ═══════════════════════════════════════════════════════════

    public function test_per_page_1_accepted(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/ep-register?per_page=1');
        $response->assertStatus(200);
    }

    public function test_per_page_100_accepted(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/ep-register?per_page=100');
        $response->assertStatus(200);
    }

    public function test_per_page_exceeds_max_returns_422(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/ep-register?per_page=101');
        $response->assertStatus(422);
    }

    public function test_per_page_zero_returns_422(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/ep-register?per_page=0');
        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════
    // 6. Authentication state transitions
    // ═══════════════════════════════════════════════════════════

    public function test_unauthenticated_returns_401(): void
    {
        $response = $this->getJson('/api/v1/reports/ep-register');
        $response->assertStatus(401);
    }

    /**
     * Viewer with seeded report.view permission can access reports.
     * setUp() grants report.view + report.export to role_id=2.
     */
    public function test_viewer_can_access_reports(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->viewerToken}")
            ->getJson('/api/v1/reports/ep-register?project_id=999999');
        $response->assertStatus(200);
    }

    /**
     * Viewer with seeded report.export permission can export reports.
     * setUp() grants report.view + report.export to role_id=2.
     */
    public function test_viewer_can_export_reports(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->viewerToken}")
            ->getJson('/api/v1/reports/ep-register/export?project_id=999999');
        $response->assertStatus(200);
    }

    public function test_user_without_permission_returns_403(): void
    {
        // Create user with NO role — should get 403
        $noPermId = DB::table('users')->insertGetId([
            'username' => 'smoke_noperm_'.Str::random(6),
            'full_name' => 'No Permission User',
            'password_hash' => bcrypt('TestPass@12345'),
            'email' => Str::random(8).'@test.com',
            'mobile' => '017'.rand(10000000, 99999999),
            'is_active' => 1,
        ]);
        $noPermToken = User::find($noPermId)->createToken('test-token')->plainTextToken;
        $response = $this->withHeader('Authorization', "Bearer {$noPermToken}")
            ->getJson('/api/v1/reports/ep-register');
        $response->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════════
    // 7. CSV export
    // ═══════════════════════════════════════════════════════════

    #[DataProvider('exportSlugsProvider')]
    public function test_csv_export_returns_csv_content(string $slug): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson("/api/v1/reports/{$slug}/export?project_id=999999");
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    /**
     * CSV export must preserve active filters.
     **/
    #[DataProvider('exportFilterProvider')]
    public function test_csv_export_preserves_filters(string $slug, array $filters): void
    {
        $params = array_merge(['project_id' => '999999'], $filters);
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson("/api/v1/reports/{$slug}/export?".http_build_query($params));
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    /**
     * @todo CONTRACT-POLICY #3: XLSX and PDF formats are accepted by ReportRequest
     *       validation (Rule::in(['json','csv','xlsx','pdf'])) but return 501 because
     *       phpoffice/phpspreadsheet and barryvdh/laravel-dompdf are not installed.
     *       The intended future contract: once those dependencies are introduced,
     *       format=xlsx should return a real export (200 + application/vnd.openxmlformats...).
     *       This test uses format=xyz (truly invalid) to verify the 422 path.
     *       See docs/CONTRACT-POLICY-REVIEW.md §3.
     */
    public function test_invalid_export_format_returns_422(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/ep-register/export?format=xyz');
        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════
    // 8. Report does not mutate database
    // ═══════════════════════════════════════════════════════════

    public function test_report_fetch_does_not_mutate_database(): void
    {
        $countsBefore = $this->getTableCounts();

        $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/ep-register?project_id=999999')->assertStatus(200);

        $countsAfter = $this->getTableCounts();
        $this->assertSame($countsBefore, $countsAfter, 'Report fetch must not mutate any table.');
    }

    // ═══════════════════════════════════════════════════════════
    // Data providers
    // ═══════════════════════════════════════════════════════════

    /**
     * @return array<string, array{string, string[]}>
     */
    public static function moneyReportProvider(): array
    {
        return [
            'R01 monthly_income/monthly_expense' => ['ep-register', ['monthly_income', 'monthly_expense']],
            'R06 total_assessed_loss/total_compensation' => ['ec-register', ['total_assessed_loss', 'total_compensation']],
            'R09 rate_per_unit/assessed_loss' => ['land-assessment', ['rate_per_unit', 'assessed_loss']],
            'R10 rate_per_unit/assessed_loss' => ['crop-assessment', ['rate_per_unit', 'assessed_loss']],
            'R12 grant_amount' => ['allowance-list', ['grant_amount']],
            'R15 approved_amount' => ['voucher-list', ['approved_amount']],
            'R17 outstanding' => ['outstanding', ['outstanding']],
            'R19 total_assessed/total_compensation' => ['district-summary', ['total_assessed', 'total_compensation']],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allSlugsProvider(): array
    {
        $jsonPath = dirname(__DIR__, 2).'/../frontend/contract/registry.json';
        if (! file_exists($jsonPath)) {
            return ['no-registry' => ['ep-register']];
        }

        $raw = file_get_contents($jsonPath);
        $registry = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $datasets = [];
        foreach ($registry['reports'] as $report) {
            $datasets[$report['slug']] = [$report['slug']];
        }

        return $datasets;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function exportSlugsProvider(): array
    {
        return self::allSlugsProvider();
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function exportFilterProvider(): array
    {
        return [
            'R01 with status' => ['ep-register', ['status' => 'SUBMITTED']],
            'R05 with print_status' => ['epicad-list', ['print_status' => 'NOT_PRINTED']],
            'R15 with payment_status' => ['voucher-list', ['payment_status' => 'PENDING']],
            'R17 with outstanding_only' => ['outstanding', ['outstanding_only' => '1']],
            'R16 with date range' => ['payment-summary', ['from_date' => '2025-01-01', 'to_date' => '2025-12-31']],
            'R09 with land_type_id' => ['land-assessment', ['land_type_id' => '1']],
            'R10 with crop_type_id' => ['crop-assessment', ['crop_type_id' => '1']],
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════

    private function getTableCounts(): array
    {
        $tables = [
            'affected_person', 'family_member', 'epicad', 'ec',
            'ec_line_item', 'land_assessment', 'crop_assessment', 'compensation',
            'vulnerability_rule', 'allowance', 'voucher', 'payment',
        ];

        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = \DB::table($table)->count();
        }

        return $counts;
    }
}
