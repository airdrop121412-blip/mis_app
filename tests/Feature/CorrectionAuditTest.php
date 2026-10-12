<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Data-correction API tests — Phase F.
 *
 * Field-level corrections with full audit trail. Financial corrections
 * require authorization from an approver (ep.approve permission).
 */
class CorrectionAuditTest extends TestCase
{
    use DatabaseTransactions;

    private string $token;

    private int $userId;

    private int $projectId;

    private int $epId;

    protected function setUp(): void
    {
        parent::setUp();

        // Admin user + token (role 1 = ADMIN, has all permissions)
        $this->userId = DB::table('users')->insertGetId([
            'username' => 'corr_admin_'.Str::random(8),
            'full_name' => 'Correction Admin',
            'password_hash' => bcrypt('AdminPass@123'),
            'is_active' => 1,
        ]);
        DB::table('user_role')->insert([
            'user_id' => $this->userId,
            'role_id' => 1, // ADMIN
        ]);

        $this->token = Str::random(60);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $this->userId,
            'name' => 'test',
            'token' => hash('sha256', $this->token),
            'abilities' => json_encode(['*']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Project
        $this->projectId = DB::table('project')->insertGetId([
            'name' => 'Correction Test Project',
            'project_code' => 'CTP-'.Str::random(5),
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'is_active' => 1,
        ]);

        // DRAFT EP (corrections require DRAFT status for EP)
        $this->epId = $this->createDraftEp();
    }

    // ── Tests ────────────────────────────────────────────────────

    public function test_index_requires_project_id(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/corrections');

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_index_returns_corrections_for_project(): void
    {
        // Apply a correction first so there is data
        $this->applyCorrection('mobile', '01711111111');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/corrections?project_id='.$this->projectId);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'correction_audit_id',
                    'entity_type',
                    'entity_id',
                    'field_name',
                    'old_value',
                    'new_value',
                    'correction_reason',
                    'financial_impact',
                    'authorized',
                ],
            ],
            'meta' => ['pagination' => ['total', 'current_page', 'per_page', 'last_page']],
        ]);
    }

    public function test_store_applies_correction_with_audit_trail(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/corrections', [
                'entity_type' => 'EP',
                'entity_id' => $this->epId,
                'field_name' => 'mobile',
                'new_value' => '01822222222',
                'correction_reason' => 'Corrected from paper record',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.field_name', 'mobile');
        $response->assertJsonPath('data.new_value', '01822222222');
        $response->assertJsonPath('data.financial_impact', false);
        $response->assertJsonPath('data.affected_person_id', $this->epId);

        // Verify the entity was actually updated
        $this->assertSame('01822222222', DB::table('affected_person')->where('affected_person_id', $this->epId)->value('mobile'));

        // Verify audit row
        $audit = DB::table('correction_audit')->where('entity_type', 'EP')
            ->where('entity_id', $this->epId)
            ->where('field_name', 'mobile')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame('01822222222', $audit->new_value);
        $this->assertSame($this->userId, (int) $audit->corrected_by);
        $this->assertSame((string) $this->projectId, (string) $audit->project_id);
    }

    public function test_store_rejects_no_change(): void
    {
        $currentMobile = DB::table('affected_person')->where('affected_person_id', $this->epId)->value('mobile');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/corrections', [
                'entity_type' => 'EP',
                'entity_id' => $this->epId,
                'field_name' => 'mobile',
                'new_value' => $currentMobile,
                'correction_reason' => 'No actual change',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'No change detected.');
    }

    public function test_store_rejects_non_draft_ep(): void
    {
        // Submit the EP first
        DB::table('affected_person')->where('affected_person_id', $this->epId)->update(['status' => 'SUBMITTED']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/corrections', [
                'entity_type' => 'EP',
                'entity_id' => $this->epId,
                'field_name' => 'mobile',
                'new_value' => '01933333333',
                'correction_reason' => 'Should be rejected',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'EP must be in DRAFT status to edit (current: SUBMITTED).');
    }

    public function test_store_rejects_financial_correction_without_authorization(): void
    {
        // Editor user with ep.edit (passes middleware) but NOT ep.approve
        $editorToken = $this->createEditorToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$editorToken)
            ->postJson('/api/v1/corrections', [
                'entity_type' => 'EP',
                'entity_id' => $this->epId,
                'field_name' => 'monthly_income',
                'new_value' => '9000',
                'correction_reason' => 'Income correction',
            ]);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Financial corrections require authorization from an approved approver.');
    }

    public function test_store_allows_financial_correction_when_authorized(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/corrections', [
                'entity_type' => 'EP',
                'entity_id' => $this->epId,
                'field_name' => 'monthly_income',
                'new_value' => '9000',
                'correction_reason' => 'Income correction authorized by approver',
                'authorized' => true,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.financial_impact', true);

        $audit = DB::table('correction_audit')->where('entity_type', 'EP')
            ->where('entity_id', $this->epId)
            ->where('field_name', 'monthly_income')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame(1, (int) $audit->financial_impact);
        $this->assertSame(1, (int) $audit->authorized);
        $this->assertSame($this->userId, (int) $audit->authorized_by);
    }

    public function test_history_returns_audit_trail(): void
    {
        $this->applyCorrection('mobile', '01755555555');
        $this->applyCorrection('name', 'Corrected Name');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson("/api/v1/corrections/EP/{$this->epId}/history");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'correction_audit_id',
                    'entity_type',
                    'entity_id',
                    'field_name',
                    'old_value',
                    'new_value',
                    'correction_reason',
                    'corrected_by_user',
                ],
            ],
        ]);
    }

    public function test_history_returns_empty_for_unknown_entity(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/corrections/EP/999999/history');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function applyCorrection(string $field, string $newValue): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/corrections', [
                'entity_type' => 'EP',
                'entity_id' => $this->epId,
                'field_name' => $field,
                'new_value' => $newValue,
                'correction_reason' => 'Test correction',
            ])
            ->assertCreated();
    }

    private function createDraftEp(): int
    {
        $districtId = DB::table('district')->insertGetId([
            'name' => 'Corr District '.Str::random(5),
            'code' => (string) rand(10, 99),
            'is_active' => 1,
        ]);
        $upazilaId = DB::table('upazila')->insertGetId([
            'name' => 'Corr Upazila '.Str::random(5),
            'code' => (string) rand(10, 99),
            'district_id' => $districtId,
            'is_active' => 1,
        ]);

        return DB::table('affected_person')->insertGetId([
            'project_id' => $this->projectId,
            'ep_id' => 'CORR'.rand(1000, 9999),
            'name' => 'Correction Person',
            'nid_number' => (string) rand(1000000000, 9999999999),
            'mobile' => '017'.rand(10000000, 99999999),
            'gender_id' => 1,
            'district_id' => $districtId,
            'upazila_id' => $upazilaId,
            'land_status_id' => 1,
            'rc_type_id' => 1,
            'monthly_income' => '5000.00',
            'monthly_expense' => '3000.00',
            'status' => 'DRAFT',
            'is_household_head' => 1,
        ]);
    }

    private function createEditorToken(): string
    {
        $userId = DB::table('users')->insertGetId([
            'username' => 'corr_editor_'.Str::random(8),
            'full_name' => 'Editor',
            'password_hash' => bcrypt('TestPass@123'),
            'is_active' => 1,
        ]);

        // Surveyor role (id 2) has ep.edit but NOT ep.approve
        DB::table('user_role')->insert([
            'user_id' => $userId,
            'role_id' => 2,
        ]);

        $token = Str::random(60);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $userId,
            'name' => 'test',
            'token' => hash('sha256', $token),
            'abilities' => json_encode(['*']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $token;
    }
}
