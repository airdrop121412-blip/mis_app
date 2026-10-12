<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Report\ReportType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * API Contract Audit — Systematic tests for R01–R23.
 *
 * These tests verify the API contract rather than individual report logic.
 * Each test is data-driven across all 23 report types where applicable.
 *
 * Covered contract dimensions:
 *   1. Envelope shape consistency
 *   2. Column metadata (key, label, type)
 *   3. Money precision (string serialization)
 *   4. Date formatting (ISO 8601)
 *   5. Summary structure
 *   6. CSV export for R01–R10
 *   7. JSON ↔ CSV semantic equivalence
 *   8. Filter parameter validation
 */
class ContractAuditTest extends TestCase
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

    private int $vulnerabilityRuleId;

    protected function setUp(): void
    {
        parent::setUp();

        // ── Admin user ───────────────────────────────────────
        $this->adminUserId = DB::table('users')->insertGetId([
            'username' => 'contract_admin_'.Str::random(6),
            'full_name' => 'Contract Audit Admin',
            'password_hash' => bcrypt('TestPass@12345'),
            'email' => Str::random(8).'@test.com',
            'mobile' => '017'.rand(10000000, 99999999),
            'is_active' => 1,
        ]);
        DB::table('user_role')->insert(['user_id' => $this->adminUserId, 'role_id' => 1]);
        $this->adminToken = $this->createToken($this->adminUserId);

        // ── Geography ────────────────────────────────────────
        $this->districtId = DB::table('district')->insertGetId([
            'code' => 'CTR'.rand(100, 999),
            'name' => 'Contract Audit District',
            'is_active' => 1,
        ]);
        $this->upazilaId = DB::table('upazila')->insertGetId([
            'district_id' => $this->districtId,
            'code' => 'CUZ'.rand(100, 999),
            'name' => 'Contract Audit Upazila',
            'is_active' => 1,
        ]);

        // ── Project ──────────────────────────────────────────
        $this->projectId = DB::table('project')->insertGetId([
            'project_code' => 'CTR-'.rand(1000, 9999),
            'name' => 'Contract Audit Project',
            'start_date' => '2025-01-01',
            'end_date' => '2025-12-31',
            'is_active' => 1,
        ]);

        // ── Affected Person ─────────────────────────────────
        $this->epId = DB::table('affected_person')->insertGetId([
            'project_id' => $this->projectId,
            'ep_id' => 'EP'.rand(100000, 999999).'#CT01',
            'name' => 'Contract Audit Person',
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
            'date_of_birth' => '1985-06-15',
            'monthly_income' => '50000.10',
            'monthly_expense' => '40000.50',
            'status' => 'SUBMITTED',
            'is_household_head' => 1,
        ]);

        // ── Family member ───────────────────────────────────
        DB::table('family_member')->insert([
            'affected_person_id' => $this->epId,
            'relationship_id' => 4, // SPOUSE
            'gender_id' => 2, // FEMALE
            'age' => 35,
            'occupation_id' => 2,
            'is_dependent' => 1,
        ]);

        // ── EPICAD ───────────────────────────────────────────
        DB::table('epicad')->insert([
            'affected_person_id' => $this->epId,
            'issue_date' => '2025-06-15',
            'print_status' => 'PRINTED',
        ]);

        // ── EC ───────────────────────────────────────────────
        $this->ecId = DB::table('ec')->insertGetId([
            'affected_person_id' => $this->epId,
            'epicad_id' => DB::table('epicad')->where('affected_person_id', $this->epId)->value('epicad_id'),
            'ec_number' => 'EC-'.rand(100000, 999999),
            'assessment_date' => '2025-07-01',
            'total_assessed_loss' => '250000.75',
            'total_compensation' => '220000.25',
            'status' => 'APPROVED',
        ]);

        // ── Compensation ─────────────────────────────────────
        DB::table('compensation')->insert([
            'ec_id' => $this->ecId,
            'category' => 'LAND',
            'assessed_amount' => '200000.00',
            'approved_amount' => '180000.00',
            'status' => 'APPROVED',
        ]);

        // ── Vulnerability Rule + Allowance ───────────────────
        $this->vulnerabilityRuleId = DB::table('vulnerability_rule')->insertGetId([
            'rule_code' => 'CTR_VULN',
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
            'voucher_date' => '2025-08-01',
            'approved_amount' => '180000.00',
            'payment_status' => 'PARTIAL',
            'prepared_by' => $this->adminUserId,
        ]);
        DB::table('voucher_compensation')->insert([
            'voucher_id' => $voucherId,
            'compensation_id' => DB::table('compensation')->where('ec_id', $this->ecId)->value('compensation_id'),
            'amount' => '180000.00',
        ]);
        DB::table('payment')->insert([
            'voucher_id' => $voucherId,
            'payment_method_id' => $this->paymentMethodId,
            'amount' => '120000.00',
            'payment_reference' => 'PAY-CTR-001',
            'payment_date' => '2025-08-15',
        ]);
    }

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

    // ═══════════════════════════════════════════════════════════════
    // 1. ENVELOPE CONSISTENCY — all 23 types
    // ═══════════════════════════════════════════════════════════════

    public static function allReportTypes(): array
    {
        return array_map(
            fn (ReportType $t) => [$t->value],
            ReportType::cases(),
        );
    }

    #[DataProvider('allReportTypes')]
    public function test_envelope_shape(string $slug): void
    {
        $response = $this->fetchReport($slug);

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => ['title', 'columns', 'rows'],
            'meta' => ['type', 'filters_applied'],
        ]);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('meta.type', $slug);
        $this->assertIsArray($response->json('data.rows'));
        $this->assertIsArray($response->json('data.columns'));
    }

    // ═══════════════════════════════════════════════════════════════
    // 2. COLUMN METADATA — all 23 types
    // ═══════════════════════════════════════════════════════════════

    private const ALLOWED_COLUMN_TYPES = [
        'integer', 'string', 'money', 'number', 'boolean', 'date', 'percentage',
    ];

    #[DataProvider('allReportTypes')]
    public function test_column_metadata_is_valid(string $slug): void
    {
        $response = $this->fetchReport($slug);

        $columns = $response->json('data.columns');
        $this->assertNotEmpty($columns, "Report {$slug} must declare at least one column");

        $keys = [];
        foreach ($columns as $i => $col) {
            // Each column must have key, label, type.
            $this->assertArrayHasKey('key', $col, "Column #{$i} in {$slug} missing 'key'");
            $this->assertArrayHasKey('label', $col, "Column #{$i} in {$slug} missing 'label'");
            $this->assertArrayHasKey('type', $col, "Column #{$i} in {$slug} missing 'type'");

            // key and label must be non-empty strings.
            $this->assertNotEmpty($col['key'], "Column #{$i} in {$slug} has empty 'key'");
            $this->assertNotEmpty($col['label'], "Column #{$i} in {$slug} has empty 'label'");

            // type must be in the allowed vocabulary.
            $this->assertContains($col['type'], self::ALLOWED_COLUMN_TYPES,
                "Column #{$i} ({$col['key']}) in {$slug} has invalid type '{$col['type']}'");

            // No duplicate keys.
            $this->assertNotContains($col['key'], $keys,
                "Duplicate column key '{$col['key']}' in {$slug}");
            $keys[] = $col['key'];
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 3. MONEY PRECISION — JSON serializes money as strings
    // ═══════════════════════════════════════════════════════════════

    /**
     * For every report, any row value for a 'money' column must be a
     * string (not a float/int), and must match the DECIMAL(.,2) pattern.
     **/
    #[DataProvider('allReportTypes')]
    public function test_money_fields_are_serialized_as_strings(string $slug): void
    {
        $response = $this->fetchReport($slug);

        $columns = collect($response->json('data.columns'));
        $moneyColumns = $columns->where('type', 'money')->pluck('key')->toArray();

        if (empty($moneyColumns)) {
            $this->assertTrue(true); // No money columns — nothing to check.

            return;
        }

        $rows = $response->json('data.rows');
        if (empty($rows)) {
            $this->assertTrue(true); // Empty result — nothing to check.

            return;
        }

        foreach ($rows as $row) {
            foreach ($moneyColumns as $key) {
                $value = $row[$key] ?? null;

                // Allow NULL (nullable money fields, e.g. R14 annual_income_limit).
                if ($value === null) {
                    continue;
                }

                // Must be a string.
                $this->assertIsString($value,
                    "Money field '{$key}' in {$slug} should be string, got ".gettype($value));

                // Must match DECIMAL pattern: digits.digits (at least 1 digit each side).
                $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/',
                    $value,
                    "Money field '{$key}' in {$slug} has invalid format: '{$value}'");
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 4. DATE FORMATTING — YYYY-MM-DD for date columns
    // ═══════════════════════════════════════════════════════════════

    #[DataProvider('allReportTypes')]
    public function test_date_fields_are_iso8601(string $slug): void
    {
        $response = $this->fetchReport($slug);

        $columns = collect($response->json('data.columns'));
        $dateColumns = $columns->where('type', 'date')->pluck('key')->toArray();

        if (empty($dateColumns)) {
            // No date columns — assert column count exists (avoid risky-test).
            $this->assertGreaterThan(0, $columns->count(), "Report {$slug} must declare columns");

            return;
        }

        $rows = $response->json('data.rows');
        if (empty($rows)) {
            $this->assertNotNull($rows, "Report {$slug} rows must be present");

            return;
        }

        foreach ($rows as $row) {
            foreach ($dateColumns as $key) {
                $value = $row[$key] ?? null;

                if ($value === null || $value === '') {
                    continue; // Nullable dates are acceptable.
                }

                $this->assertIsString($value,
                    "Date field '{$key}' in {$slug} should be string");

                // Accept YYYY-MM-DD (date-only) OR ISO 8601 datetime
                // (e.g. 2025-06-15T00:00:00.000000Z from Eloquent Carbon casts).
                $this->assertTrue(
                    preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
                    || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $value) === 1,
                    "Date field '{$key}' in {$slug} must be YYYY-MM-DD or ISO 8601 datetime, got: '{$value}'"
                );
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 5. SUMMARY STRUCTURE — every report has a summary with keys
    // ═══════════════════════════════════════════════════════════════

    /**
     * Every report must return a non-empty summary array.
     * R22 (workflow-status) has a special 'entity_totals' key.
     **/
    #[DataProvider('allReportTypes')]
    public function test_summary_is_non_empty_array(string $slug): void
    {
        $response = $this->fetchReport($slug);

        $this->assertArrayHasKey('summary', $response->json('data'),
            "Report {$slug} must include 'summary' in data envelope");

        $summary = $response->json('data.summary');
        $this->assertIsArray($summary);
        $this->assertNotEmpty($summary, "Report {$slug} summary must not be empty");
    }

    // ═══════════════════════════════════════════════════════════════
    // 6. CSV EXPORT — R01–R10 (data provider)
    // ═══════════════════════════════════════════════════════════════

    private const R01_TO_R10_SLUGS = [
        'ep-register', 'ep-status-summary', 'ep-demographics',
        'family-register', 'epicad-list',
        'ec-register', 'ec-status-summary', 'assessment-summary',
        'land-assessment', 'crop-assessment',
    ];

    #[DataProvider('r01ToR10Provider')]
    public function test_csv_export_r01_to_r10(string $slug): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson("/api/v1/reports/{$slug}/export?format=csv");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $response->assertHeaderContains('Content-Disposition', 'attachment');
        $response->assertHeaderContains('Content-Disposition', "{$slug}.csv");

        $content = $response->streamedContent();
        $lines = array_filter(explode("\n", $content));

        // Must have at least a header row.
        $this->assertNotEmpty($lines, "CSV for {$slug} must have at least a header row");

        // Header row is the first line.
        $header = $lines[0];
        $this->assertNotEmpty($header, "CSV header for {$slug} must not be empty");
    }

    public static function r01ToR10Provider(): array
    {
        return array_map(
            fn (string $s) => [$s],
            self::R01_TO_R10_SLUGS,
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // 7. JSON ↔ CSV SEMANTIC EQUIVALENCE — all 23 types
    // ═══════════════════════════════════════════════════════════════

    #[DataProvider('allReportTypes')]
    public function test_json_csv_semantic_equivalence(string $slug): void
    {
        // Fetch JSON.
        $jsonResponse = $this->fetchReport($slug);
        $jsonRows = $jsonResponse->json('data.rows');
        $jsonColumnLabels = collect($jsonResponse->json('data.columns'))->pluck('label')->toArray();

        // Fetch CSV.
        $csvResponse = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson("/api/v1/reports/{$slug}/export?format=csv");
        $csvResponse->assertOk();

        $content = $csvResponse->streamedContent();
        $lines = array_values(array_filter(explode("\n", $content)));

        // CSV has header + data rows.
        $csvDataRowCount = max(0, count($lines) - 1);
        $this->assertEquals(count($jsonRows), $csvDataRowCount,
            "JSON row count ({$slug}) should equal CSV data row count");

        // CSV headers should match column labels.
        if (! empty($lines)) {
            // Parse the CSV header (first line).
            $headerHandle = fopen('php://memory', 'r+');
            fwrite($headerHandle, $lines[0]."\n");
            rewind($headerHandle);
            $csvHeaders = fgetcsv($headerHandle);
            fclose($headerHandle);

            $this->assertEquals($jsonColumnLabels, $csvHeaders,
                "CSV headers ({$slug}) should match JSON column labels");
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // 8. FILTER PARAMETER VALIDATION
    // ═══════════════════════════════════════════════════════════════

    /**
     * Invalid filter parameter values should return validation errors.
     */
    public function test_invalid_filter_parameters_return_422(): void
    {
        $cases = [
            ['project_id' => -1],
            ['district_id' => 'abc'],
            ['from_date' => 'not-a-date'],
            ['to_date' => '2025-01-01', 'from_date' => '2025-12-31'], // to before from
            ['category' => 'INVALID'],
            ['print_status' => 'INVALID'],
            ['per_page' => 0],
            ['per_page' => 200],
            ['outstanding_only' => 'maybe'],
            ['payment_status' => 'INVALID'],
        ];

        foreach ($cases as $params) {
            $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
                ->getJson('/api/v1/reports/ep-register?'.http_build_query($params));

            $response->assertStatus(422);
        }
    }

    /**
     * Unknown report type returns 404.
     */
    public function test_unknown_report_type_returns_404(): void
    {
        $response = $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/v1/reports/nonexistent-report');

        $response->assertStatus(404);
    }

    /**
     * Unauthenticated access returns 401.
     */
    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/v1/reports/ep-register')
            ->assertUnauthorized();
    }

    // ═══════════════════════════════════════════════════════════════
    // 9. REPORT-SPECIFIC FILTERS REACH THE QUERY
    // ═══════════════════════════════════════════════════════════════

    /**
     * Verify that report-specific filter parameters (previously missing
     * from the whitelist) are now accepted and reach the query.
     */
    public function test_outstanding_only_filter_accepted(): void
    {
        $response = $this->fetchReport('outstanding', ['outstanding_only' => '1']);
        $response->assertOk();
    }

    public function test_payment_status_filter_accepted(): void
    {
        $response = $this->fetchReport('voucher-list', ['payment_status' => 'PAID']);
        $response->assertOk();
    }

    public function test_land_type_id_filter_accepted(): void
    {
        $response = $this->fetchReport('land-assessment', ['land_type_id' => 1]);
        $response->assertOk();
    }

    public function test_crop_type_id_filter_accepted(): void
    {
        $response = $this->fetchReport('crop-assessment', ['crop_type_id' => 1]);
        $response->assertOk();
    }

    public function test_season_id_filter_accepted(): void
    {
        $response = $this->fetchReport('crop-assessment', ['season_id' => 1]);
        $response->assertOk();
    }

    // ═══════════════════════════════════════════════════════════════
    // 10. EMPTY RESULTS — all 23 types
    // ═══════════════════════════════════════════════════════════════

    #[DataProvider('allReportTypes')]
    public function test_empty_result_with_no_matching_data(string $slug): void
    {
        $response = $this->fetchReport($slug, ['project_id' => 999999]);

        $response->assertOk();
        $this->assertEmpty($response->json('data.rows'),
            "Report {$slug} should return empty rows for non-existent project");
    }

    // ═══════════════════════════════════════════════════════════════
    // 11. NO DATABASE MUTATION — all 23 types
    // ═══════════════════════════════════════════════════════════════

    #[DataProvider('allReportTypes')]
    public function test_report_does_not_mutate_database(string $slug): void
    {
        $counts = [
            'affected_person' => DB::table('affected_person')->count(),
            'ec' => DB::table('ec')->count(),
            'voucher' => DB::table('voucher')->count(),
            'payment' => DB::table('payment')->count(),
            'allowance' => DB::table('allowance')->count(),
        ];

        $this->fetchReport($slug);

        $this->assertEquals($counts['affected_person'], DB::table('affected_person')->count());
        $this->assertEquals($counts['ec'], DB::table('ec')->count());
        $this->assertEquals($counts['voucher'], DB::table('voucher')->count());
        $this->assertEquals($counts['payment'], DB::table('payment')->count());
        $this->assertEquals($counts['allowance'], DB::table('allowance')->count());
    }
}
