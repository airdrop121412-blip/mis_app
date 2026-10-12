<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Frontend Column ↔ API Response Contract (Phase 4B-7f-3).
 *
 * Actually executes every report endpoint and compares the HTTP response
 * column metadata against the frontend registry (contract/registry.json).
 *
 * This catches RUNTIME column drift:
 *   Registry says `total_assessed_loss`, API actually returns `assessed_loss`.
 *
 * Uses project_id=999999 to get empty results — we only need column metadata,
 * not actual data rows.
 *
 * CRITICAL: `npm run export-contract` must be run BEFORE this test.
 */
class FrontendColumnContractTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array{reports: array} */
    private array $registry;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();

        // Load frontend registry
        $jsonPath = dirname(__DIR__, 2).'/../frontend/contract/registry.json';
        static::assertFileExists($jsonPath, 'registry.json not found.');

        $raw = file_get_contents($jsonPath);
        $this->registry = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        // Create authenticated admin user (direct DB insert + role + token)
        $userId = DB::table('users')->insertGetId([
            'username' => 'col_contract_'.Str::random(6),
            'full_name' => 'Column Contract Admin',
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
    // Column metadata audit — HTTP level
    // ═══════════════════════════════════════════════════════════

    /**
     * Every frontend column key must appear in the API response's
     * data.columns[] array.
     **/
    #[DataProvider('allSlugsProvider')]
    public function test_api_response_contains_all_frontend_columns(string $slug): void
    {
        $frontendReport = $this->getRegistryReport($slug);
        $frontendKeys = array_column($frontendReport['columns'], 'key');

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson("/api/v1/reports/{$slug}?project_id=999999");
        $response->assertStatus(200);

        $apiColumns = $response->json('data.columns');
        static::assertIsArray($apiColumns, "{$slug}: data.columns is not an array.");
        $apiKeys = array_column($apiColumns, 'key');

        foreach ($frontendKeys as $frontendKey) {
            $this->assertContains(
                $frontendKey,
                $apiKeys,
                "{$slug}: frontend column '{$frontendKey}' not found in API response columns.",
            );
        }
    }

    /**
     * API response column types must match the frontend registry types.
     **/
    #[DataProvider('allSlugsProvider')]
    public function test_api_column_types_match_registry(string $slug): void
    {
        $frontendReport = $this->getRegistryReport($slug);
        $frontendByKey = [];
        foreach ($frontendReport['columns'] as $col) {
            $frontendByKey[$col['key']] = $col;
        }

        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson("/api/v1/reports/{$slug}?project_id=999999");
        $response->assertStatus(200);

        $apiColumns = $response->json('data.columns');
        static::assertIsArray($apiColumns);

        foreach ($apiColumns as $apiCol) {
            $key = $apiCol['key'];
            if (isset($frontendByKey[$key])) {
                $this->assertSame(
                    $frontendByKey[$key]['type'],
                    $apiCol['type'],
                    "{$slug}.{$key}: frontend type '{$frontendByKey[$key]['type']}' !== API type '{$apiCol['type']}'.",
                );
            }
        }
    }

    /**
     * Every API response column must have key, label, and type.
     **/
    #[DataProvider('allSlugsProvider')]
    public function test_api_columns_have_required_metadata(string $slug): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson("/api/v1/reports/{$slug}?project_id=999999");
        $response->assertStatus(200);

        $apiColumns = $response->json('data.columns');
        static::assertIsArray($apiColumns);
        static::assertNotEmpty($apiColumns, "{$slug}: API returned no columns.");

        foreach ($apiColumns as $i => $col) {
            $this->assertArrayHasKey('key', $col, "{$slug}: column[{$i}] missing 'key'.");
            $this->assertArrayHasKey('label', $col, "{$slug}: column[{$i}] missing 'label'.");
            $this->assertArrayHasKey('type', $col, "{$slug}: column[{$i}] missing 'type'.");
            $this->assertNotEmpty($col['key'], "{$slug}: column[{$i}] has empty 'key'.");
            $this->assertNotEmpty($col['label'], "{$slug}: column[{$i}] has empty 'label'.");
        }
    }

    /**
     * API response must include data.title matching the ReportType label.
     **/
    #[DataProvider('allSlugsProvider')]
    public function test_api_title_matches_report_type_label(string $slug): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson("/api/v1/reports/{$slug}?project_id=999999");
        $response->assertStatus(200);

        $frontendReport = $this->getRegistryReport($slug);
        $response->assertJsonPath('data.title', $frontendReport['label']);
    }

    /**
     * API response meta.type must match the slug.
     **/
    #[DataProvider('allSlugsProvider')]
    public function test_api_meta_type_matches_slug(string $slug): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson("/api/v1/reports/{$slug}?project_id=999999");
        $response->assertStatus(200);
        $response->assertJsonPath('meta.type', $slug);
    }

    // ═══════════════════════════════════════════════════════════
    // Data providers
    // ═══════════════════════════════════════════════════════════

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

        static::fail("Report '{$slug}' not found in registry.");

        return [];
    }
}
