<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EC (Evidence Collection) tests — architecture §5.3.
 *
 * Covers: CRUD, status transitions, line items, recalculate.
 */
class EcTest extends TestCase
{
    use DatabaseTransactions;

    private string $token;

    private int $userId;

    private int $projectId;

    private int $epId;

    private int $epicadId;

    protected function setUp(): void
    {
        parent::setUp();

        // Create admin user + token
        $this->userId = DB::table('users')->insertGetId([
            'username' => 'ec_admin_'.Str::random(8),
            'full_name' => 'EC Admin',
            'password_hash' => bcrypt('AdminPass@123'),
            'is_active' => 1,
        ]);
        DB::table('user_role')->insert([
            'user_id' => $this->userId,
            'role_id' => 1,
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

        // Create project
        $this->projectId = DB::table('project')->insertGetId([
            'name' => 'EC Test Project',
            'project_code' => 'ETP-'.Str::random(5),
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'is_active' => 1,
        ]);

        // Create EP in SUBMITTED status
        $districtId = DB::table('district')->insertGetId([
            'name' => 'EC District '.Str::random(5),
            'code' => (string) rand(10, 99),
            'is_active' => 1,
        ]);
        $upazilaId = DB::table('upazila')->insertGetId([
            'name' => 'EC Upazila '.Str::random(5),
            'code' => (string) rand(10, 99),
            'district_id' => $districtId,
            'is_active' => 1,
        ]);

        $this->epId = DB::table('affected_person')->insertGetId([
            'project_id' => $this->projectId,
            'ep_id' => 'EC'.rand(1000, 9999),
            'name' => 'EC Test Person',
            'nid_number' => (string) rand(1000000000, 9999999999),
            'mobile' => '017'.rand(10000000, 99999999),
            'gender_id' => 1,
            'district_id' => $districtId,
            'upazila_id' => $upazilaId,
            'land_status_id' => 1,
            'rc_type_id' => 1,
            'monthly_income' => '5000.00',
            'monthly_expense' => '3000.00',
            'status' => 'SUBMITTED',
            'is_household_head' => 1,
        ]);

        DB::table('status_history')->insert([
            'entity_type' => 'App\\Models\\AffectedPerson',
            'entity_id' => $this->epId,
            'previous_status' => 'DRAFT',
            'new_status' => 'SUBMITTED',
            'changed_by' => $this->userId,
            'remarks' => 'Submitted',
            'changed_at' => now(),
        ]);

        // Create EPICAD
        $this->epicadId = DB::table('epicad')->insertGetId([
            'affected_person_id' => $this->epId,
            'issue_date' => now()->toDateString(),
            'print_status' => 'NOT_PRINTED',
        ]);
    }

    // ── Show / Create ────────────────────────────────────────────

    public function test_show_returns_404_when_no_ec(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson("/api/v1/ep/{$this->epId}/ec");

        $response->assertStatus(404);
    }

    public function test_create_ec_when_epicad_exists(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/ec");

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'DRAFT');
        $response->assertJsonPath('data.epicad_id', $this->epicadId);
        $response->assertJsonStructure(['data' => ['ec_number']]);
    }

    public function test_create_ec_without_epicad_auto_creates_epicad(): void
    {
        // Create EP without EPICAD
        $epId = $this->createEpWithoutEpicad();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$epId}/ec");

        // EPICAD is auto-created for EPs that do not have one yet,
        // so legacy approved EPs can still receive an EC/voucher.
        $response->assertCreated();
        $this->assertDatabaseHas('epicad', ['affected_person_id' => $epId]);
    }

    public function test_create_duplicate_ec_is_rejected(): void
    {
        // Create first EC
        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/ec")
            ->assertCreated();

        // Try to create second
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/ec");

        $response->assertStatus(422);
    }

    // ── Update ───────────────────────────────────────────────────

    public function test_update_ec_in_draft(): void
    {
        $this->createEc();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson("/api/v1/ep/{$this->epId}/ec", [
                'remarks' => 'Updated remarks',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.remarks', 'Updated remarks');
    }

    public function test_update_ec_in_submitted_is_rejected(): void
    {
        $this->createEc();
        $this->submitEc();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson("/api/v1/ep/{$this->epId}/ec", [
                'remarks' => 'Should fail',
            ]);

        $response->assertStatus(422);
    }

    // ── Status Transitions ───────────────────────────────────────

    public function test_submit_ec_transitions_draft_to_submitted(): void
    {
        $this->createEc();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/ec/submit");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'SUBMITTED');
    }

    public function test_verify_ec_transitions_submitted_to_verified(): void
    {
        $this->createEc();
        $this->submitEc();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/ec/verify");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'VERIFIED');
    }

    public function test_approve_ec_transitions_verified_to_approved(): void
    {
        $this->createEc();
        $this->submitEc();
        $this->verifyEc();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/ec/approve");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'APPROVED');
    }

    public function test_invalid_transition_draft_to_verified_rejected(): void
    {
        $this->createEc();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/ec/verify");

        $response->assertStatus(422);
    }

    public function test_invalid_transition_draft_to_approved_rejected(): void
    {
        $this->createEc();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/ec/approve");

        $response->assertStatus(422);
    }

    // ── Status History ───────────────────────────────────────────

    public function test_status_history_recorded_on_ec_transitions(): void
    {
        $this->createEc();
        $this->submitEc();
        $this->verifyEc();

        // Get EC ID
        $ec = DB::table('ec')->where('affected_person_id', $this->epId)->first();

        $history = DB::table('status_history')
            ->where('entity_type', 'EC')
            ->where('entity_id', $ec->ec_id)
            ->orderBy('status_history_id')
            ->get();

        $this->assertCount(3, $history); // DRAFT (create) + SUBMITTED + VERIFIED
        $this->assertEquals('DRAFT', $history[0]->new_status);
        $this->assertEquals('SUBMITTED', $history[1]->new_status);
        $this->assertEquals('VERIFIED', $history[2]->new_status);
    }

    // ── Line Items ───────────────────────────────────────────────

    public function test_create_line_item(): void
    {
        $this->createEc();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/ec/line-item", [
                'category' => 'LAND',
                'description' => 'Land damage assessment',
                'assessed_amount' => '50000.00',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.category', 'LAND');
    }

    public function test_duplicate_category_line_item_rejected(): void
    {
        $this->createEc();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/ec/line-item", [
                'category' => 'LAND',
                'assessed_amount' => '50000.00',
            ])
            ->assertCreated();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/ec/line-item", [
                'category' => 'LAND',
                'assessed_amount' => '60000.00',
            ]);

        $response->assertStatus(422);
    }

    public function test_line_item_create_rejected_when_ec_not_draft(): void
    {
        $this->createEc();
        $this->submitEc();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/ec/line-item", [
                'category' => 'LAND',
                'assessed_amount' => '50000.00',
            ]);

        $response->assertStatus(422);
    }

    public function test_update_line_item_in_draft(): void
    {
        $this->createEc();

        $lineItemId = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/ec/line-item", [
                'category' => 'LAND',
                'description' => 'Initial',
                'assessed_amount' => '50000.00',
            ])
            ->assertCreated()
            ->json('data.ec_line_item_id');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson("/api/v1/ep/{$this->epId}/ec/line-item/{$lineItemId}", [
                'description' => 'Updated description',
                'assessed_amount' => '55000.00',
                'remarks' => 'Revised',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.assessed_amount', '55000.00');
        $response->assertJsonPath('data.description', 'Updated description');
    }

    public function test_update_line_item_rejected_when_ec_not_draft(): void
    {
        $this->createEc();

        $lineItemId = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/ec/line-item", [
                'category' => 'LAND',
                'assessed_amount' => '50000.00',
            ])
            ->assertCreated()
            ->json('data.ec_line_item_id');

        $this->submitEc();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson("/api/v1/ep/{$this->epId}/ec/line-item/{$lineItemId}", [
                'assessed_amount' => '60000.00',
            ]);

        $response->assertStatus(422);
    }

    public function test_delete_line_item_in_draft(): void
    {
        $this->createEc();

        $lineItemId = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/ec/line-item", [
                'category' => 'LAND',
                'assessed_amount' => '50000.00',
            ])
            ->assertCreated()
            ->json('data.ec_line_item_id');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->deleteJson("/api/v1/ep/{$this->epId}/ec/line-item/{$lineItemId}");

        $response->assertOk();

        // Verify it's gone
        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson("/api/v1/ep/{$this->epId}/ec/line-item")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_delete_line_item_rejected_when_ec_not_draft(): void
    {
        $this->createEc();

        $lineItemId = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/ec/line-item", [
                'category' => 'LAND',
                'assessed_amount' => '50000.00',
            ])
            ->assertCreated()
            ->json('data.ec_line_item_id');

        $this->submitEc();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->deleteJson("/api/v1/ep/{$this->epId}/ec/line-item/{$lineItemId}");

        $response->assertStatus(422);
    }

    // ── Recalculate ──────────────────────────────────────────────

    public function test_recalculate_ec_totals(): void
    {
        $this->createEc();

        // Create two line items
        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/ec/line-item", [
                'category' => 'LAND',
                'assessed_amount' => '50000.00',
            ])
            ->assertCreated();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/ec/line-item", [
                'category' => 'CROP',
                'assessed_amount' => '30000.00',
            ])
            ->assertCreated();

        // Recalculate
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/ec/recalculate");

        $response->assertOk();
        $response->assertJsonPath('data.total_assessed_loss', '80000.00');
    }

    // ── Auth ─────────────────────────────────────────────────────

    public function test_unauthenticated_gets_401(): void
    {
        $response = $this->postJson("/api/v1/ep/{$this->epId}/ec");

        $response->assertStatus(401);
    }

    public function test_no_permission_gets_403(): void
    {
        $noPermToken = $this->createNoPermToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$noPermToken)
            ->postJson("/api/v1/ep/{$this->epId}/ec");

        $response->assertStatus(403);
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function createEc(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/ec")
            ->assertCreated();
    }

    private function submitEc(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/ec/submit")
            ->assertOk();
    }

    private function verifyEc(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/ec/verify")
            ->assertOk();
    }

    private function createEpWithoutEpicad(): int
    {
        $districtId = DB::table('district')->insertGetId([
            'name' => 'NoEpicad District '.Str::random(5),
            'code' => 'NE'.rand(100, 999).Str::random(4),
            'is_active' => 1,
        ]);
        $upazilaId = DB::table('upazila')->insertGetId([
            'name' => 'NoEpicad Upazila '.Str::random(5),
            'code' => 'NE'.rand(100, 999).Str::random(4),
            'district_id' => $districtId,
            'is_active' => 1,
        ]);

        return DB::table('affected_person')->insertGetId([
            'project_id' => $this->projectId,
            'ep_id' => 'NOEP'.rand(1000, 9999),
            'name' => 'No Epicad Person',
            'nid_number' => (string) rand(1000000000, 9999999999),
            'mobile' => '017'.rand(10000000, 99999999),
            'gender_id' => 1,
            'district_id' => $districtId,
            'upazila_id' => $upazilaId,
            'land_status_id' => 1,
            'rc_type_id' => 1,
            'monthly_income' => '5000.00',
            'monthly_expense' => '3000.00',
            'status' => 'SUBMITTED',
            'is_household_head' => 1,
        ]);
    }

    private function createNoPermToken(): string
    {
        $userId = DB::table('users')->insertGetId([
            'username' => 'noperm_'.Str::random(8),
            'full_name' => 'No Permission',
            'password_hash' => bcrypt('TestPass@123'),
            'is_active' => 1,
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
