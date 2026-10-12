<?php

namespace Tests\Feature;

use App\Services\Report\ReportService;
use App\Services\Report\ReportType;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Frontend Registry ↔ Backend Contract Audit (Phase 4B-7f-2).
 *
 * Reads the generated `contract/registry.json` artifact from the React
 * frontend and validates every structural claim against the authoritative
 * backend source (ReportType enum, ReportQuery classes, ReportRequest).
 *
 * This test catches STRUCTURAL DRIFT before any HTTP request is made.
 *
 * CRITICAL: `npm run export-contract` must be run BEFORE this test to
 * ensure `registry.json` reflects the current `reportRegistry.ts`.
 */
class FrontendRegistryAuditTest extends TestCase
{
    /** @var array{version: string, reports: array} */
    private array $registry;

    private ReportService $reportService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reportService = app(ReportService::class);

        // Read the generated registry artifact
        $jsonPath = base_path('../frontend/contract/registry.json');
        static::assertFileExists(
            $jsonPath,
            'registry.json not found. Run `npm run export-contract` in the frontend directory first.',
        );

        $raw = file_get_contents($jsonPath);
        static::assertNotFalse($raw, 'Failed to read registry.json');

        $this->registry = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    // ═══════════════════════════════════════════════════════════
    // Structural validation
    // ═══════════════════════════════════════════════════════════

    public function test_registry_contains_exactly_26_reports(): void
    {
        $this->assertCount(26, $this->registry['reports']);
        $this->assertSame(26, $this->registry['reportCount']);
    }

    public function test_registry_version_is_1(): void
    {
        $this->assertSame('1.0', $this->registry['version']);
    }

    public function test_registry_has_generated_at_timestamp(): void
    {
        $this->assertArrayHasKey('generatedAt', $this->registry);
        $this->assertNotEmpty($this->registry['generatedAt']);
    }

    public function test_all_frontend_slugs_match_backend_enum(): void
    {
        $backendSlugs = array_map(
            fn (ReportType $t) => $t->value,
            ReportType::cases(),
        );

        foreach ($this->registry['reports'] as $report) {
            $this->assertContains(
                $report['slug'],
                $backendSlugs,
                "Frontend slug '{$report['slug']}' has no matching backend ReportType case.",
            );
        }
    }

    public function test_all_backend_slugs_present_in_frontend(): void
    {
        $frontendSlugs = array_column($this->registry['reports'], 'slug');

        foreach (ReportType::cases() as $type) {
            $this->assertContains(
                $type->value,
                $frontendSlugs,
                "Backend ReportType {$type->name} ({$type->value}) is missing from frontend registry.",
            );
        }
    }

    public function test_no_duplicate_slugs_in_frontend(): void
    {
        $slugs = array_column($this->registry['reports'], 'slug');
        $unique = array_unique($slugs);

        $this->assertSame(
            count($slugs),
            count($unique),
            'Duplicate slugs found: '.implode(', ', array_diff_key($slugs, $unique)),
        );
    }

    public function test_no_duplicate_column_keys_within_report(): void
    {
        foreach ($this->registry['reports'] as $report) {
            $keys = array_column($report['columns'], 'key');
            $unique = array_unique($keys);

            $this->assertSame(
                count($keys),
                count($unique),
                "{$report['slug']}: duplicate column keys — ".implode(', ', array_diff_key($keys, $unique)),
            );
        }
    }

    // ═══════════════════════════════════════════════════════════
    // Column validation against backend query classes
    // ═══════════════════════════════════════════════════════════

    /**
     * For every report, every frontend column key must exist in the
     * backend query's authoritative column definition.
     **/
    #[DataProvider('frontendReportProvider')]
    public function test_frontend_column_keys_exist_in_backend(string $slug): void
    {
        $frontendReport = $this->getRegistryReport($slug);
        $backendColumns = $this->getBackendColumns($slug);
        $backendKeys = array_column($backendColumns, 'key');

        foreach ($frontendReport['columns'] as $column) {
            $this->assertContains(
                $column['key'],
                $backendKeys,
                "{$slug}: frontend column '{$column['key']}' not found in backend query.",
            );
        }
    }

    /**
     * For every report, every frontend column type must be in the
     * allowed vocabulary and match the backend's declared type.
     **/
    #[DataProvider('frontendReportProvider')]
    public function test_frontend_column_types_match_backend(string $slug): void
    {
        $allowedTypes = ['string', 'integer', 'number', 'money', 'date', 'boolean', 'percentage'];

        $frontendReport = $this->getRegistryReport($slug);
        $backendColumns = $this->getBackendColumns($slug);
        $backendByKey = array_column($backendColumns, null, 'key');

        foreach ($frontendReport['columns'] as $column) {
            // Validate type is in allowed vocabulary
            $this->assertContains(
                $column['type'],
                $allowedTypes,
                "{$slug}.{$column['key']}: invalid type '{$column['type']}'.",
            );

            // If backend also declares this column, types must match
            if (isset($backendByKey[$column['key']]['type'])) {
                $this->assertSame(
                    $backendByKey[$column['key']]['type'],
                    $column['type'],
                    "{$slug}.{$column['key']}: frontend type '{$column['type']}' !== backend type '{$backendByKey[$column['key']]['type']}'.",
                );
            }
        }
    }

    /**
     * Backend may have columns the frontend doesn't display — that's OK.
     * But the frontend must NOT claim columns the backend doesn't return.
     **/
    #[DataProvider('frontendReportProvider')]
    public function test_frontend_does_not_claim_extra_columns(string $slug): void
    {
        $frontendReport = $this->getRegistryReport($slug);
        $backendColumns = $this->getBackendColumns($slug);
        $backendKeys = array_column($backendColumns, 'key');

        $frontendKeys = array_column($frontendReport['columns'], 'key');
        $extraFrontend = array_diff($frontendKeys, $backendKeys);

        $this->assertEmpty(
            $extraFrontend,
            "{$slug}: frontend declares columns not in backend: ".implode(', ', $extraFrontend),
        );
    }

    // ═══════════════════════════════════════════════════════════
    // Filter validation against ReportRequest whitelist
    // ═══════════════════════════════════════════════════════════

    /**
     * Every filter name in the frontend registry must be accepted by
     * ReportRequest's validation rules (the backend whitelist).
     **/
    #[DataProvider('frontendReportProvider')]
    public function test_frontend_filter_names_are_in_backend_whitelist(string $slug): void
    {
        $frontendReport = $this->getRegistryReport($slug);

        $whitelist = [
            'project_id', 'district_id', 'upazila_id', 'status',
            'from_date', 'to_date', 'category', 'rule_code',
            'payment_method_id', 'print_status', 'per_page', 'format',
            'land_type_id', 'crop_type_id', 'season_id',
            'payment_status', 'outstanding_only',
        ];

        foreach ($frontendReport['filters'] as $filter) {
            $this->assertContains(
                $filter['name'],
                $whitelist,
                "{$slug}: frontend filter '{$filter['name']}' is not in the backend ReportRequest whitelist.",
            );
        }
    }

    /**
     * No frontend filter should be named 'per_page' or 'format' —
     * those are infrastructure params, not report filters.
     **/
    #[DataProvider('frontendReportProvider')]
    public function test_frontend_filters_exclude_infrastructure_params(string $slug): void
    {
        $frontendReport = $this->getRegistryReport($slug);
        $filterNames = array_column($frontendReport['filters'], 'name');

        $this->assertNotContains('per_page', $filterNames, "{$slug}: 'per_page' should not be a frontend filter.");
        $this->assertNotContains('format', $filterNames, "{$slug}: 'format' should not be a frontend filter.");
    }

    // ═══════════════════════════════════════════════════════════
    // Group and metadata validation
    // ═══════════════════════════════════════════════════════════

    public function test_all_reports_have_non_empty_groups(): void
    {
        foreach ($this->registry['reports'] as $report) {
            $this->assertNotEmpty($report['group'], "{$report['slug']}: group is empty.");
            $this->assertIsString($report['group']);
        }
    }

    public function test_all_reports_have_non_empty_labels(): void
    {
        foreach ($this->registry['reports'] as $report) {
            $this->assertNotEmpty($report['label'], "{$report['slug']}: label is empty.");
            $this->assertSame(
                ReportType::fromSlug($report['slug'])?->label(),
                $report['label'],
                "{$report['slug']}: frontend label '{$report['label']}' doesn't match backend label.",
            );
        }
    }

    public function test_only_r22_has_entity_totals(): void
    {
        foreach ($this->registry['reports'] as $report) {
            if ($report['slug'] === 'workflow-status') {
                $this->assertTrue(
                    $report['hasEntityTotals'],
                    'R22 (workflow-status) should have hasEntityTotals = true.',
                );
            } else {
                $this->assertFalse(
                    $report['hasEntityTotals'],
                    "{$report['slug']}: only R22 should have hasEntityTotals = true.",
                );
            }
        }
    }

    public function test_only_payment_reports_have_date_range(): void
    {
        foreach ($this->registry['reports'] as $report) {
            if (in_array($report['slug'], ['payment-summary', 'swtpp-payment-register'], true)) {
                $this->assertTrue(
                    $report['hasDateRange'],
                    "{$report['slug']} should have hasDateRange = true.",
                );
            } else {
                $this->assertFalse(
                    $report['hasDateRange'],
                    "{$report['slug']}: only payment reports should have hasDateRange = true.",
                );
            }
        }
    }

    public function test_every_report_has_at_least_one_filter(): void
    {
        foreach ($this->registry['reports'] as $report) {
            $this->assertNotEmpty(
                $report['filters'],
                "{$report['slug']}: no filters defined. Every report must have at least project_id.",
            );
        }
    }

    public function test_every_report_has_at_least_one_column(): void
    {
        foreach ($this->registry['reports'] as $report) {
            $this->assertNotEmpty(
                $report['columns'],
                "{$report['slug']}: no columns defined.",
            );
        }
    }

    // ═══════════════════════════════════════════════════════════
    // Data providers
    // ═══════════════════════════════════════════════════════════

    /**
     * @return array<string, array{string}>
     */
    public static function frontendReportProvider(): array
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

    // ═══════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════

    private function getRegistryReport(string $slug): array
    {
        foreach ($this->registry['reports'] as $report) {
            if ($report['slug'] === $slug) {
                return $report;
            }
        }

        static::fail("Frontend report '{$slug}' not found in registry.");

        return []; // unreachable
    }

    /**
     * Get the authoritative column definitions from the backend query class.
     */
    private function getBackendColumns(string $slug): array
    {
        $type = ReportType::fromSlug($slug);
        static::assertNotNull($type, "Backend ReportType not found for slug '{$slug}'.");

        if (! $this->reportService->isImplemented($type)) {
            static::fail("Backend query not implemented for '{$slug}'.");

            return [];
        }

        $query = $this->reportService->resolveQuery($type);
        $result = $query->execute();

        return $result['columns'] ?? [];
    }
}
