<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Allowance tests — vulnerability-based grants for affected persons.
 *
 * Uses direct DB inserts for all test data to avoid session contamination.
 */
class AllowanceTest extends TestCase
{
    use DatabaseTransactions;

    private string $token;

    private int $userId;

    private int $projectId;

    private int $epId;

    private int $districtId;

    private int $upazilaId;

    private int $ruleId;

    protected function setUp(): void
    {
        parent::setUp();

        // Admin user + token
        $this->userId = DB::table('users')->insertGetId([
            'username' => 'allow_admin_'.Str::random(8),
            'full_name' => 'Allowance Admin',
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

        // Project
        $this->projectId = DB::table('project')->insertGetId([
            'name' => 'Allowance Test Project',
            'project_code' => 'ATP-'.Str::random(5),
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'is_active' => 1,
        ]);

        // District + Upazila
        $this->districtId = DB::table('district')->insertGetId([
            'name' => 'Allow District '.Str::random(5),
            'code' => (string) rand(10, 99),
            'is_active' => 1,
        ]);
        $this->upazilaId = DB::table('upazila')->insertGetId([
            'name' => 'Allow Upazila '.Str::random(5),
            'code' => (string) rand(10, 99),
            'district_id' => $this->districtId,
            'is_active' => 1,
        ]);

        // EP (SUBMITTED)
        $this->epId = DB::table('affected_person')->insertGetId([
            'project_id' => $this->projectId,
            'ep_id' => 'ALW'.rand(1000, 9999),
            'name' => 'Allowance Person',
            'nid_number' => (string) rand(1000000000, 9999999999),
            'mobile' => '017'.rand(10000000, 99999999),
            'gender_id' => 1,
            'district_id' => $this->districtId,
            'upazila_id' => $this->upazilaId,
            'land_status_id' => 1,
            'rc_type_id' => 1,
            'monthly_income' => '5000.00',
            'monthly_expense' => '3000.00',
            'status' => 'SUBMITTED',
            'is_household_head' => 1,
        ]);

        // Use existing vulnerability_rule (seeded)
        $this->ruleId = DB::table('vulnerability_rule')->value('vulnerability_rule_id');
    }

    // ═══════════════════════════════════════════════════════════════
    // INDEX
    // ═══════════════════════════════════════════════════════════════

    public function test_index_returns_empty_when_no_allowances(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson("/api/v1/ep/{$this->epId}/allowance");

        $response->assertOk();
        $response->assertJsonPath('data', []);
    }

    public function test_index_lists_allowances(): void
    {
        $this->createAllowance();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson("/api/v1/ep/{$this->epId}/allowance");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    // ═══════════════════════════════════════════════════════════════
    // SHOW
    // ═══════════════════════════════════════════════════════════════

    public function test_show_returns_404_when_no_allowance(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson("/api/v1/ep/{$this->epId}/allowance/999999");

        $response->assertStatus(404);
    }

    public function test_show_returns_allowance(): void
    {
        $id = $this->createAllowance();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson("/api/v1/ep/{$this->epId}/allowance/{$id}");

        $response->assertOk();
        $response->assertJsonPath('data.allowance_id', $id);
        $response->assertJsonPath('data.status', 'DRAFT');
    }

    // ═══════════════════════════════════════════════════════════════
    // STORE
    // ═══════════════════════════════════════════════════════════════

    public function test_store_creates_allowance(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/allowance", [
                'vulnerability_rule_id' => $this->ruleId,
                'is_eligible' => true,
                'grant_amount' => '5000.00',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'DRAFT');
        $response->assertJsonPath('data.vulnerability_rule_id', $this->ruleId);
    }

    public function test_store_duplicate_rule_rejected(): void
    {
        $this->createAllowance();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/allowance", [
                'vulnerability_rule_id' => $this->ruleId,
                'is_eligible' => true,
                'grant_amount' => '5000.00',
            ]);

        $response->assertStatus(422);
    }

    public function test_store_missing_rule_rejected(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/allowance", [
                'vulnerability_rule_id' => 999999,
                'is_eligible' => true,
                'grant_amount' => '5000.00',
            ]);

        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════
    // UPDATE
    // ═══════════════════════════════════════════════════════════════

    public function test_update_draft_allowance(): void
    {
        $id = $this->createAllowance();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson("/api/v1/ep/{$this->epId}/allowance/{$id}", [
                'remarks' => 'Updated',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.remarks', 'Updated');
    }

    public function test_update_approved_allowance_rejected(): void
    {
        $id = $this->createAllowance();
        DB::table('allowance')->where('allowance_id', $id)->update(['status' => 'APPROVED']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson("/api/v1/ep/{$this->epId}/allowance/{$id}", [
                'remarks' => 'Should fail',
            ]);

        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════
    // DELETE
    // ═══════════════════════════════════════════════════════════════

    public function test_delete_draft_allowance(): void
    {
        $id = $this->createAllowance();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->deleteJson("/api/v1/ep/{$this->epId}/allowance/{$id}");

        $response->assertOk();
        $this->assertDatabaseCount('allowance', 0);
    }

    // ═══════════════════════════════════════════════════════════════
    // STATUS TRANSITIONS
    // ═══════════════════════════════════════════════════════════════

    public function test_approve_draft_to_approved(): void
    {
        $id = $this->createAllowance();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/allowance/{$id}/approve");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'APPROVED');
    }

    public function test_reject_draft_to_rejected(): void
    {
        $id = $this->createAllowance();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/allowance/{$id}/reject");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'REJECTED');
    }

    public function test_mark_paid_approved_to_paid(): void
    {
        $id = $this->createAllowance();
        DB::table('allowance')->where('allowance_id', $id)->update(['status' => 'APPROVED']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/allowance/{$id}/mark-paid");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'PAID');
    }

    public function test_invalid_transition_draft_to_paid_rejected(): void
    {
        $id = $this->createAllowance();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/allowance/{$id}/mark-paid");

        $response->assertStatus(422);
    }

    public function test_status_history_recorded(): void
    {
        $id = $this->createAllowance();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/allowance/{$id}/approve")
            ->assertOk();

        $historyCount = DB::table('status_history')
            ->where('entity_type', 'ALLOWANCE')
            ->where('entity_id', $id)
            ->count();

        $this->assertGreaterThanOrEqual(1, $historyCount); // at least APPROVED transition
    }

    // ═══════════════════════════════════════════════════════════════
    // AUTH
    // ═══════════════════════════════════════════════════════════════

    public function test_unauthenticated_gets_401(): void
    {
        $response = $this->getJson("/api/v1/ep/{$this->epId}/allowance");

        $response->assertStatus(401);
    }

    public function test_no_permission_gets_403(): void
    {
        $noPermUserId = DB::table('users')->insertGetId([
            'username' => 'noperm_'.Str::random(8),
            'full_name' => 'No Perm',
            'password_hash' => bcrypt('pass'),
            'is_active' => 1,
        ]);

        $noPermToken = Str::random(60);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $noPermUserId,
            'name' => 'test',
            'token' => hash('sha256', $noPermToken),
            'abilities' => json_encode(['*']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$noPermToken)
            ->getJson("/api/v1/ep/{$this->epId}/allowance");

        $response->assertStatus(403);
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function createAllowance(): int
    {
        return DB::table('allowance')->insertGetId([
            'affected_person_id' => $this->epId,
            'vulnerability_rule_id' => $this->ruleId,
            'is_eligible' => 1,
            'grant_amount' => '5000.00',
            'status' => 'DRAFT',
        ]);
    }
}
