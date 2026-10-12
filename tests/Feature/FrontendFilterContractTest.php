<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Frontend Filter ↔ ReportRequest Contract (Phase 4B-7f-4).
 *
 * Validates that every filter registered in the frontend registry
 * survives the full HTTP → ReportRequest → toFilters() → Query pipeline.
 *
 * For each report's filters:
 *   1. Sends the filter as an HTTP query parameter
 *   2. Verifies the request is accepted (200, not 422)
 *   3. Verifies the filter appears in meta.filters_applied
 *
 * This specifically protects the five filters that previously exposed
 * the whitelist problem (land_type_id, crop_type_id, season_id,
 * payment_status, outstanding_only).
 *
 * CRITICAL: `npm run export-contract` must be run BEFORE this test.
 */
class FrontendFilterContractTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array{reports: array} */
    private array $registry;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        $jsonPath = dirname(__DIR__, 2).'/../frontend/contract/registry.json';
        static::assertFileExists($jsonPath, 'registry.json not found.');

        $raw = file_get_contents($jsonPath);
        $this->registry = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        $userId = DB::table('users')->insertGetId([
            'username' => 'filter_contract_'.Str::random(6),
            'full_name' => 'Filter Contract Admin',
            'password_hash' => bcrypt('TestPass@12345'),
            'email' => Str::random(8).'@test.com',
            'mobile' => '017'.rand(10000000, 99999999),
            'is_active' => 1,
        ]);
        DB::table('user_role')->insert(['user_id' => $userId, 'role_id' => 1]);
        $user = User::find($userId);
        $this->adminToken = $user->createToken('test-token')->plainTextToken;
    }

    // ═══════════════════════════════════════════════════════════
    // Filter acceptance tests
    // ═══════════════════════════════════════════════════════════

    /**
     * Every frontend filter must survive the HTTP → ReportRequest →
     * toFilters() pipeline without being rejected.
     **/
    #[DataProvider('allFilterCombinationsProvider')]
    public function test_frontend_filter_is_accepted_by_report(string $slug, string $filterName, ?string $filterValue): void
    {
        $params = ['project_id' => '999999', $filterName => $filterValue ?? '1'];
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson("/api/v1/reports/{$slug}?".http_build_query($params));

        $response->assertStatus(200, "{$slug}: filter '{$filterName}={$filterValue}' was rejected.");
    }

    /**
     * Filters accepted by the backend must appear in meta.filters_applied.
     **/
    #[DataProvider('specificFilterProvider')]
    public function test_filter_appears_in_meta_filters_applied(string $slug, string $filterName, string $filterValue): void
    {
        $params = ['project_id' => '999999', $filterName => $filterValue];
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson("/api/v1/reports/{$slug}?".http_build_query($params));

        $response->assertStatus(200);
        $response->assertJsonStructure(['meta' => ['filters_applied']]);
        $this->assertArrayHasKey(
            $filterName,
            $response->json('meta.filters_applied') ?? [],
            "{$slug}: filter '{$filterName}' not present in meta.filters_applied.",
        );
    }

    /**
     * All 23 reports must accept project_id filter.
     **/
    #[DataProvider('allSlugsProvider')]
    public function test_project_id_filter_accepted_on_all_reports(string $slug): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson("/api/v1/reports/{$slug}?project_id=999999");
        $response->assertStatus(200);
        $response->assertJsonPath('meta.filters_applied.project_id', '999999');
    }

    /**
     * All reports except R21 (project-summary) must accept district_id.
     **/
    #[DataProvider('districtCapableSlugsProvider')]
    public function test_district_id_filter_accepted(string $slug): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson("/api/v1/reports/{$slug}?project_id=999999&district_id=999999");
        $response->assertStatus(200);
    }

    // ═══════════════════════════════════════════════════════════
    // Filter rejection tests
    // ═══════════════════════════════════════════════════════════

    /**
     * @todo CONTRACT-POLICY #1: Unknown parameters are currently silently ignored.
     *       ReportRequest does not reject extra keys; toFilters() drops them.
     *       The intended future contract: unknown parameters should be rejected
     *       with 422, catching frontend contract drift at the API boundary.
     *       This test documents the current permissive behavior.
     *       See docs/CONTRACT-POLICY-REVIEW.md §1.
     */
    public function test_unknown_filter_param_is_silently_ignored(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/ep-register?project_id=999999&totally_bogus_param=1');
        $response->assertStatus(200);
        // Bogus param must NOT appear in filters_applied
        $filtersApplied = $response->json('meta.filters_applied') ?? [];
        $this->assertArrayNotHasKey('totally_bogus_param', $filtersApplied);
    }

    /**
     * @todo CONTRACT-POLICY #2: The status field currently accepts any string
     *       up to 30 chars (nullable|string|max:30). The intended future contract:
     *       status should be validated against an enum of known values (DRAFT,
     *       SUBMITTED, APPROVED, REJECTED, etc.), consistent with how payment_status,
     *       print_status, and outstanding_only are validated via Rule::in().
     *       This test documents the current permissive behavior.
     *       See docs/CONTRACT-POLICY-REVIEW.md §2.
     */
    public function test_loose_status_validation_is_accepted(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/ep-register?project_id=999999&status=INVALID_STATUS_XYZ');
        $response->assertStatus(200);
    }

    public function test_invalid_payment_status_returns_422(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/voucher-list?payment_status=BOGUS');
        $response->assertStatus(422);
    }

    public function test_invalid_print_status_returns_422(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/epicad-list?print_status=BOGUS');
        $response->assertStatus(422);
    }

    public function test_invalid_outstanding_only_returns_422(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/outstanding?outstanding_only=5');
        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════
    // Data providers
    // ═══════════════════════════════════════════════════════════

    /**
     * Generates test cases for every (report, filter) combination
     * from the frontend registry.
     *
     * @return array<string, array{string, string, string|null}>
     */
    public static function allFilterCombinationsProvider(): array
    {
        $datasets = [];
        foreach (self::getRegistryReports() as $report) {
            foreach ($report['filters'] as $filter) {
                $value = match ($filter['name']) {
                    'project_id' => '999999',
                    'district_id' => '999999',
                    'upazila_id' => '999999',
                    'status' => 'SUBMITTED',
                    'print_status' => 'NOT_PRINTED',
                    'payment_status' => 'PENDING',
                    'outstanding_only' => '1',
                    'land_type_id' => '999999',
                    'crop_type_id' => '999999',
                    'season_id' => '999999',
                    'from_date' => '2025-01-01',
                    'to_date' => '2025-12-31',
                    default => '1',
                };
                $key = "{$report['slug']}:{$filter['name']}";
                $datasets[$key] = [$report['slug'], $filter['name'], $value];
            }
        }

        return $datasets;
    }

    /**
     * Specific filter test cases for the 5 critical filters.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function specificFilterProvider(): array
    {
        return [
            'outstanding on R17' => ['outstanding', 'outstanding_only', '1'],
            'payment_status on R15' => ['voucher-list', 'payment_status', 'PAID'],
            'land_type_id on R09' => ['land-assessment', 'land_type_id', '1'],
            'crop_type_id on R10' => ['crop-assessment', 'crop_type_id', '1'],
            'season_id on R10' => ['crop-assessment', 'season_id', '1'],
            'print_status on R05' => ['epicad-list', 'print_status', 'PRINTED'],
            'from_date on R16' => ['payment-summary', 'from_date', '2025-01-01'],
            'to_date on R16' => ['payment-summary', 'to_date', '2025-12-31'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function allSlugsProvider(): array
    {
        $datasets = [];
        foreach (self::getRegistryReports() as $report) {
            $datasets[$report['slug']] = [$report['slug']];
        }

        return $datasets;
    }

    /**
     * Reports that accept district_id (all except R21 project-summary).
     *
     * @return array<string, array{string}>
     */
    public static function districtCapableSlugsProvider(): array
    {
        $datasets = [];
        foreach (self::getRegistryReports() as $report) {
            if ($report['slug'] !== 'project-summary') {
                $datasets[$report['slug']] = [$report['slug']];
            }
        }

        return $datasets;
    }

    // ═══════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════

    private static function getRegistryReports(): array
    {
        $jsonPath = dirname(__DIR__, 2).'/../frontend/contract/registry.json';
        if (! file_exists($jsonPath)) {
            return [];
        }

        $raw = file_get_contents($jsonPath);
        $registry = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $registry['reports'] ?? [];
    }
}
