<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\Import\EpImportController;
use App\Http\Requests\Api\V1\Ep\AffectedPersonStoreRequest;
use App\Models\EpidCode;
use App\Models\User;
use App\Support\Xlsx\XlsxReader;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use ReflectionMethod;
use Tests\TestCase;
use ZipArchive;

/**
 * Regression tests for the production EP-import "Network error" fix.
 *
 * Covers the three failure layers found in production:
 *  1. XlsxReader — streaming single-sheet parsing. readSheet() must return
 *     results identical to readSheets() (real template + synthetic workbook
 *     with every cell type).
 *  2. memoizeExistsRules() — identical validation messages with ZERO
 *     per-row DB queries (baseline: the original rules do query).
 *  3. End-to-end POST /imports/ep/step — upload → validate → insert,
 *     including master-text resolution ("Male" → 1), in-memory NID
 *     duplicate detection, and the EpIdService per-request caches.
 *
 * Uses DatabaseTransactions — every test rolls back (never RefreshDatabase).
 */
class EpImportStepTest extends TestCase
{
    use DatabaseTransactions;

    /** Create user, assign seeded ADMIN role (role_id=1), login, return Bearer token. */
    private function adminToken(): string
    {
        $user = User::factory()->create([
            'username' => 'imp_admin_'.fake()->unique()->numerify('#####'),
            'password_hash' => bcrypt('SecretPass@123'),
        ]);

        DB::table('user_role')->insert([
            'user_id' => $user->user_id,
            'role_id' => 1, // ADMIN
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $user->username,
            'password' => 'SecretPass@123',
        ]);

        return $response->json('data.token');
    }

    /** Create a project (inside the test transaction). */
    private function createProject(): int
    {
        return DB::table('project')->insertGetId([
            'name' => 'EP Import Test Project',
            'project_code' => 'IMP-'.rand(10000, 99999),
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'is_active' => 1,
        ]);
    }

    /**
     * Create district + upazila (districts are NOT seeded) together with the
     * epid_code rows EpIdService needs, and return their ids.
     */
    private function seedImportFks(): array
    {
        $unique = (string) rand(100, 999);
        $districtName = 'Import Test District '.$unique;
        $upazilaName = 'Import Test Upazila '.$unique;

        $districtId = DB::table('district')->insertGetId([
            'name' => $districtName,
            'code' => $unique,
            'is_active' => 1,
        ]);

        $upazilaId = DB::table('upazila')->insertGetId([
            'name' => $upazilaName,
            'code' => $unique,
            'district_id' => $districtId,
            'is_active' => 1,
        ]);

        DB::table('epid_code')->insert([
            [
                'project_id' => null,
                'category' => EpidCode::CATEGORY_DISTRICT,
                'name' => $districtName,
                'code' => '77',
                'is_active' => 1,
            ],
            [
                'project_id' => null,
                'category' => EpidCode::CATEGORY_UPAZILA,
                'name' => $upazilaName,
                'code' => '88',
                'is_active' => 1,
            ],
        ]);

        return compact('districtId', 'upazilaId');
    }

    // ── Synthetic workbook helpers ─────────────────────────────────

    private function colRef(int $c): string
    {
        $s = '';
        while ($c > 0) {
            $m = ($c - 1) % 26;
            $s = chr(65 + $m).$s;
            $c = intdiv($c - 1, 26);
        }

        return $s;
    }

    /**
     * Build a minimal .xlsx readable by XlsxReader: workbook.xml + rels and
     * one worksheet part per entry (in order). When $shared is given, a
     * shared-strings table is written and 's' cells can reference it.
     *
     * @param  array<string, string> $sheets  sheet name => sheet XML
     * @return string raw .xlsx bytes
     */
    private function buildWorkbook(array $sheets, array $shared = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'impxlsx').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($shared !== []) {
            $xml = '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($shared).'" uniqueCount="'.count($shared).'">';
            foreach ($shared as $s) {
                $xml .= '<si><t>'.htmlspecialchars((string) $s, ENT_XML1).'</t></si>';
            }
            $zip->addFromString('xl/sharedStrings.xml', $xml.'</sst>');
        }

        $wkb = '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $n = 0;
        foreach (array_keys($sheets) as $name) {
            $n++;
            $wkb .= '<sheet name="'.htmlspecialchars($name, ENT_XML1).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
            $rels .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
        }
        $wkb .= '</sheets></workbook>';
        $rels .= '</Relationships>';
        $zip->addFromString('xl/workbook.xml', $wkb);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);

        $n = 0;
        foreach ($sheets as $xml) {
            $n++;
            $zip->addFromString('xl/worksheets/sheet'.$n.'.xml', $xml);
        }
        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** Sheet XML: row 1 = header codes, then value rows (inline strings). */
    private function sheetXml(array $headers, array $rows): string
    {
        $xml = '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

        $xml .= '<row r="1">';
        foreach (array_values($headers) as $i => $h) {
            $ref = $this->colRef($i + 1).'1';
            $xml .= '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars((string) $h, ENT_XML1).'</t></is></c>';
        }
        $xml .= '</row>';

        foreach (array_values($rows) as $ri => $row) {
            $r = $ri + 2;
            $xml .= '<row r="'.$r.'">';
            foreach (array_values($row) as $ci => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                $ref = $this->colRef($ci + 1).$r;
                $xml .= '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars((string) $v, ENT_XML1).'</t></is></c>';
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    // ── 1. XlsxReader: streaming parse parity ──────────────────────

    public function test_read_sheet_matches_read_sheets_on_real_template(): void
    {
        $path = realpath(__DIR__.'/../../../ep-import-template.xlsx');
        if ($path === false) {
            $this->markTestSkipped('ep-import-template.xlsx not present.');
        }

        $all = XlsxReader::readSheets($path);
        $this->assertNotEmpty($all);

        foreach ($all as $name => $rows) {
            $this->assertSame($rows, XlsxReader::readSheet($path, $name), "readSheet parity failed for «{$name}»");
        }

        $this->assertSame([], XlsxReader::readSheet($path, '__no_such_sheet__'));
    }

    public function test_synthetic_workbook_parses_every_cell_type(): void
    {
        $sheetXml = <<<'XML'
<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>
<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="inlineStr"><is><t>নাম</t></is></c><c r="C1"><v>42</v></c></row>
<row r="2"><c r="A2" t="s"><v>1</v></c><c r="B2" t="b"><v>1</v></c><c r="C2" t="str"><v>5.5</v></c></row>
<row r="3"><c r="A3" t="s"><v>2</v></c></row>
<row r="4"><c r="D4" t="inlineStr"><is><t>gap</t></is></c></row>
</sheetData></worksheet>
XML;

        $bytes = $this->buildWorkbook(['Data' => $sheetXml], ['alpha', 'beta', 'gamma']);

        $expected = [
            ['alpha', 'নাম', 42],
            ['beta', true, '5.5'],
            ['gamma'],
            [null, null, null, 'gap'],
        ];

        $path = tempnam(sys_get_temp_dir(), 'impxlsx').'.xlsx';
        file_put_contents($path, $bytes);

        try {
            $this->assertSame($expected, XlsxReader::readSheets($path)['Data'] ?? null);
            $this->assertSame($expected, XlsxReader::readSheet($path, 'Data'));
        } finally {
            @unlink($path);
        }
    }

    // ── 2. Validation memoisation: same errors, zero queries ───────

    public function test_memoized_exists_rules_identical_errors_with_zero_queries(): void
    {
        $projectId = $this->createProject();
        $orig = AffectedPersonStoreRequest::epRules($projectId);

        $ref = new ReflectionMethod(EpImportController::class, 'memoizeExistsRules');
        $ref->setAccessible(true);
        $memo = $ref->invoke(null, $orig);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $sample = ['gender_id' => 999999];

        $before = $queries;
        $origErrors = Validator::make($sample, $orig)->errors()->messages();
        $origCount = $queries - $before;

        $before = $queries;
        $memoErrors = Validator::make($sample, $memo)->errors()->messages();
        $memoCount = $queries - $before;

        $this->assertArrayHasKey('gender_id', $origErrors, 'baseline: the exists rule must reject the id');
        $this->assertSame(
            $origErrors['gender_id'],
            $memoErrors['gender_id'] ?? null,
            'memoised rules must produce the EXACT same message as the exists rule'
        );
        $this->assertGreaterThan(0, $origCount, 'baseline: the original rules do hit the DB');
        $this->assertSame(0, $memoCount, 'memoised rules must not issue any per-row query');

        $genderId = (int) DB::table('gender')->orderBy('gender_id')->value('gender_id');
        $before = $queries;
        $validErrors = Validator::make(['gender_id' => $genderId], $memo)->errors()->messages();
        $this->assertArrayNotHasKey('gender_id', $validErrors, 'a valid id must pass the memoised rule');
        $this->assertSame(0, $queries - $before, 'valid-id check must also stay query-free');
    }

    // ── 3. End-to-end step import ──────────────────────────────────

    public function test_step_import_ep_end_to_end(): void
    {
        $token = $this->adminToken();
        $projectId = $this->createProject();
        $fks = $this->seedImportFks();

        $headers = [
            'hh_id', 'name', 'nid_number', 'mobile', 'gender_id', 'religion_id',
            'district_id', 'upazila_id', 'land_status_id', 'rc_type_id', 'ownership',
        ];

        $rows = [
            // 1) valid — master TEXT values (the production failure mode)
            ['HHA1', 'Import Person One', '11111111111111111', '01711111111', 'Male', 'Islam', $fks['districtId'], $fks['upazilaId'], '1', 'Residential', 'Title'],
            // 2) valid — different master texts
            ['HHA2', 'Import Person Two', '22222222222222222', '01722222222', 'Female', 'Hinduism', $fks['districtId'], $fks['upazilaId'], '1', 'Commercial', 'Title'],
            // 3) duplicate NID of row 1 → in-memory unique check must skip it
            ['HHA3', 'Import Person Three', '11111111111111111', '01733333333', 'Male', 'Islam', $fks['districtId'], $fks['upazilaId'], '1', 'Residential', 'Title'],
            // 4) invalid numeric gender → memoised exists/in rule must skip it
            ['HHA4', 'Import Person Four', '44444444444444444', '01744444444', '999999', 'Islam', $fks['districtId'], $fks['upazilaId'], '1', 'Residential', 'Title'],
        ];

        $bytes = $this->buildWorkbook([
            'EP আপলোড' => $this->sheetXml($headers, $rows),
            'পরিবার' => $this->sheetXml(['name'], [['sibling sheet the reader must ignore']]),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/v1/imports/ep/step', [
                'project_id' => $projectId,
                'step' => 'ep',
                'file' => UploadedFile::fake()->createWithContent('ep_import_test.xlsx', $bytes),
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $data = $response->json('data');
        $this->assertSame(4, $data['total']);
        $this->assertSame(2, $data['imported']);
        $this->assertSame(2, $data['skipped']);

        // Row 3 (excel row 4) — duplicate NID, exact removed-unique message.
        $dupErrors = collect($data['errors'])->firstWhere('row', 4);
        $this->assertNotNull($dupErrors, 'the duplicate-NID row must be reported as skipped');
        $this->assertSame(
            ['The nid number has already been taken.'],
            $dupErrors['errors']['nid_number'] ?? null
        );

        // Row 4 (excel row 5) — invalid gender id rejected by the memoised rule.
        $genderErrors = collect($data['errors'])->firstWhere('row', 5);
        $this->assertNotNull($genderErrors, 'the invalid-gender row must be reported as skipped');
        $this->assertArrayHasKey('gender_id', $genderErrors['errors']);

        // The two good rows exist with resolved master ids and generated EP
        // ids (proves the EpIdService model/code caches work end-to-end).
        $eps = DB::table('affected_person')
            ->where('project_id', $projectId)
            ->orderBy('affected_person_id')
            ->get();

        $this->assertCount(2, $eps);
        foreach ($eps as $ep) {
            $this->assertSame('DRAFT', $ep->status);
            $this->assertNotNull($ep->ep_id);
            $this->assertStringContainsString('#', $ep->ep_id);
        }

        // Text "Male"/"Islam" resolved to master ids 1/1; "Female"/"Hinduism" → 2/2.
        $this->assertSame(1, (int) $eps[0]->gender_id);
        $this->assertSame(1, (int) $eps[0]->religion_id);
        $this->assertSame(2, (int) $eps[1]->gender_id);
        $this->assertSame(2, (int) $eps[1]->religion_id);
        $this->assertSame('11111111111111111', $eps[0]->nid_number);

        // Status history written for both imports (scoped to this test's EPs —
        // the local dev DB carries thousands of unrelated EP history rows).
        $this->assertSame(
            2,
            DB::table('status_history')
                ->where('entity_type', 'EP')
                ->whereIn('entity_id', $eps->pluck('affected_person_id'))
                ->count()
        );
    }
}

