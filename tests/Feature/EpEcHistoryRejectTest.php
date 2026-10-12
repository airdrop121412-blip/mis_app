<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4B-7a — EP History + EC Reject + EC History.
 *
 * Covers:
 *   GET  /api/v1/ep/{ep}/history          — EP status history
 *   POST /api/v1/ep/{ep}/ec/reject        — EC reject (requires remarks)
 *   GET  /api/v1/ep/{ep}/ec/history       — EC status history
 *
 * Uses direct DB inserts for all test data to avoid session contamination.
 */
class EpEcHistoryRejectTest extends TestCase
{
    use DatabaseTransactions;

    private string $token;

    private int $userId;

    private int $projectId;

    private int $epId;

    private int $epicadId;

    private int $ecId;

    private int $districtId;

    private int $upazilaId;

    protected function setUp(): void
    {
        parent::setUp();

        // Admin user + token
        $this->userId = DB::table('users')->insertGetId([
            'username' => 'hist_admin_'.Str::random(8),
            'full_name' => 'History Admin',
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
            'name' => 'History Test Project',
            'project_code' => 'HTP-'.Str::random(5),
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'is_active' => 1,
        ]);

        // District + Upazila
        $this->districtId = DB::table('district')->insertGetId([
            'name' => 'Hist District '.Str::random(5),
            'code' => (string) rand(10, 99),
            'is_active' => 1,
        ]);
        $this->upazilaId = DB::table('upazila')->insertGetId([
            'name' => 'Hist Upazila '.Str::random(5),
            'code' => (string) rand(10, 99),
            'district_id' => $this->districtId,
            'is_active' => 1,
        ]);

        // EP (VERIFIED — has gone through multiple transitions)
        $this->epId = DB::table('affected_person')->insertGetId([
            'project_id' => $this->projectId,
            'ep_id' => 'HST'.rand(1000, 9999),
            'name' => 'History Person',
            'nid_number' => (string) rand(1000000000, 9999999999),
            'mobile' => '017'.rand(10000000, 99999999),
            'gender_id' => 1,
            'district_id' => $this->districtId,
            'upazila_id' => $this->upazilaId,
            'land_status_id' => 1,
            'rc_type_id' => 1,
            'monthly_income' => '5000.00',
            'monthly_expense' => '3000.00',
            'status' => 'VERIFIED',
            'is_household_head' => 1,
        ]);

        // Seed EP status history
        DB::table('status_history')->insert([
            ['entity_type' => 'EP', 'entity_id' => $this->epId, 'previous_status' => null, 'new_status' => 'DRAFT', 'changed_by' => $this->userId, 'changed_at' => now()->subDays(3)],
            ['entity_type' => 'EP', 'entity_id' => $this->epId, 'previous_status' => 'DRAFT', 'new_status' => 'SUBMITTED', 'changed_by' => $this->userId, 'changed_at' => now()->subDays(2)],
            ['entity_type' => 'EP', 'entity_id' => $this->epId, 'previous_status' => 'SUBMITTED', 'new_status' => 'VERIFIED', 'changed_by' => $this->userId, 'changed_at' => now()->subDay()],
        ]);

        // EPICAD
        $this->epicadId = DB::table('epicad')->insertGetId([
            'affected_person_id' => $this->epId,
            'issue_date' => now()->toDateString(),
            'print_status' => 'NOT_PRINTED',
        ]);

        // EC (DRAFT)
        $this->ecId = DB::table('ec')->insertGetId([
            'affected_person_id' => $this->epId,
            'epicad_id' => $this->epicadId,
            'ec_number' => 'EC-'.now()->format('Ymd').'-0001',
            'assessment_date' => now()->toDateString(),
            'total_assessed_loss' => '0.00',
            'total_compensation' => '0.00',
            'status' => 'DRAFT',
        ]);

        // Seed EC status history
        DB::table('status_history')->insert([
            ['entity_type' => 'EC', 'entity_id' => $this->ecId, 'previous_status' => null, 'new_status' => 'DRAFT', 'changed_by' => $this->userId, 'changed_at' => now()],
        ]);
    }

    private function authHeader(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    // ═══════════════════════════════════════════════════════════════
    // EP HISTORY
    // ═══════════════════════════════════════════════════════════════

    public function test_ep_history_returns_ordered_records(): void
    {
        $response = $this->getJson("/api/v1/ep/{$this->epId}/history", $this->authHeader());

        $response->assertOk()
            ->assertJsonPath('success', true);

        $history = $response->json('data');
        $this->assertCount(3, $history);

        // Verify chronological order
        $this->assertEquals('DRAFT', $history[0]['new_status']);
        $this->assertEquals('SUBMITTED', $history[1]['new_status']);
        $this->assertEquals('VERIFIED', $history[2]['new_status']);

        // First record has null previous_status
        $this->assertNull($history[0]['previous_status']);
    }

    public function test_ep_history_includes_changed_by(): void
    {
        $response = $this->getJson("/api/v1/ep/{$this->epId}/history", $this->authHeader());

        $response->assertOk();

        $history = $response->json('data');
        $this->assertNotEmpty($history[0]['changed_by']);
    }

    public function test_ep_history_empty_for_new_ep(): void
    {
        // Create EP with no history
        $newEpId = DB::table('affected_person')->insertGetId([
            'project_id' => $this->projectId,
            'ep_id' => 'EMP'.rand(1000, 9999),
            'name' => 'Empty History',
            'nid_number' => (string) rand(1000000000, 9999999999),
            'mobile' => '017'.rand(10000000, 99999999),
            'gender_id' => 1,
            'district_id' => $this->districtId,
            'upazila_id' => $this->upazilaId,
            'land_status_id' => 1,
            'rc_type_id' => 1,
            'monthly_income' => '5000.00',
            'monthly_expense' => '3000.00',
            'status' => 'DRAFT',
            'is_household_head' => 1,
        ]);

        $response = $this->getJson("/api/v1/ep/{$newEpId}/history", $this->authHeader());

        $response->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_ep_history_404_for_nonexistent_ep(): void
    {
        $response = $this->getJson('/api/v1/ep/999999/history', $this->authHeader());

        $response->assertNotFound();
    }

    public function test_ep_history_unauthenticated(): void
    {
        $response = $this->getJson("/api/v1/ep/{$this->epId}/history");

        $response->assertUnauthorized();
    }

    public function test_ep_history_no_permission(): void
    {
        // Create user with no role
        $noRoleId = DB::table('users')->insertGetId([
            'username' => 'norole_ep_'.Str::random(6),
            'full_name' => 'No Role',
            'password_hash' => bcrypt('x'),
            'is_active' => 1,
        ]);

        $token = Str::random(60);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $noRoleId,
            'name' => 'test',
            'token' => hash('sha256', $token),
            'abilities' => json_encode(['*']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson("/api/v1/ep/{$this->epId}/history", [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertForbidden();
    }

    // ═══════════════════════════════════════════════════════════════
    // EC REJECT
    // ═══════════════════════════════════════════════════════════════

    public function test_reject_transitions_to_rejected(): void
    {
        $response = $this->postJson("/api/v1/ep/{$this->epId}/ec/reject", [
            'remarks' => 'Insufficient documentation provided.',
        ], $this->authHeader());

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'REJECTED');

        // Verify in DB
        $ec = DB::table('ec')->where('ec_id', $this->ecId)->first();
        $this->assertEquals('REJECTED', $ec->status);
    }

    public function test_reject_records_status_history(): void
    {
        $this->postJson("/api/v1/ep/{$this->epId}/ec/reject", [
            'remarks' => 'Missing assessment data.',
        ], $this->authHeader());

        $history = DB::table('status_history')
            ->where('entity_type', 'EC')
            ->where('entity_id', $this->ecId)
            ->where('new_status', 'REJECTED')
            ->first();

        $this->assertNotNull($history);
        $this->assertEquals('DRAFT', $history->previous_status);
        $this->assertEquals('Missing assessment data.', $history->remarks);
    }

    public function test_reject_requires_remarks(): void
    {
        $response = $this->postJson("/api/v1/ep/{$this->epId}/ec/reject", [], $this->authHeader());

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['remarks']);
    }

    public function test_reject_from_submitted(): void
    {
        // Transition EC to SUBMITTED first
        DB::table('ec')->where('ec_id', $this->ecId)->update(['status' => 'SUBMITTED']);
        DB::table('status_history')->insert([
            'entity_type' => 'EC', 'entity_id' => $this->ecId,
            'previous_status' => 'DRAFT', 'new_status' => 'SUBMITTED',
            'changed_by' => $this->userId, 'changed_at' => now(),
        ]);

        $response = $this->postJson("/api/v1/ep/{$this->epId}/ec/reject", [
            'remarks' => 'Needs more verification.',
        ], $this->authHeader());

        $response->assertOk()
            ->assertJsonPath('data.status', 'REJECTED');
    }

    public function test_reject_from_verified(): void
    {
        // Transition EC to VERIFIED
        DB::table('ec')->where('ec_id', $this->ecId)->update(['status' => 'VERIFIED']);
        DB::table('status_history')->insert([
            'entity_type' => 'EC', 'entity_id' => $this->ecId,
            'previous_status' => 'DRAFT', 'new_status' => 'VERIFIED',
            'changed_by' => $this->userId, 'changed_at' => now(),
        ]);

        $response = $this->postJson("/api/v1/ep/{$this->epId}/ec/reject", [
            'remarks' => 'Compensation amounts incorrect.',
        ], $this->authHeader());

        $response->assertOk()
            ->assertJsonPath('data.status', 'REJECTED');
    }

    public function test_reject_already_rejected_rejected(): void
    {
        // Set EC to REJECTED first
        DB::table('ec')->where('ec_id', $this->ecId)->update(['status' => 'REJECTED']);

        $response = $this->postJson("/api/v1/ep/{$this->epId}/ec/reject", [
            'remarks' => 'Cannot reject again.',
        ], $this->authHeader());

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_reject_approved_rejected(): void
    {
        // Set EC to APPROVED
        DB::table('ec')->where('ec_id', $this->ecId)->update(['status' => 'APPROVED']);

        $response = $this->postJson("/api/v1/ep/{$this->epId}/ec/reject", [
            'remarks' => 'Cannot reject approved.',
        ], $this->authHeader());

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_reject_404_for_no_ec(): void
    {
        // Create EP without EC
        $noEcEp = DB::table('affected_person')->insertGetId([
            'project_id' => $this->projectId,
            'ep_id' => 'NEC'.rand(1000, 9999),
            'name' => 'No EC Person',
            'nid_number' => (string) rand(1000000000, 9999999999),
            'mobile' => '017'.rand(10000000, 99999999),
            'gender_id' => 1,
            'district_id' => $this->districtId,
            'upazila_id' => $this->upazilaId,
            'land_status_id' => 1,
            'rc_type_id' => 1,
            'monthly_income' => '5000.00',
            'monthly_expense' => '3000.00',
            'status' => 'DRAFT',
            'is_household_head' => 1,
        ]);

        $response = $this->postJson("/api/v1/ep/{$noEcEp}/ec/reject", [
            'remarks' => 'No EC to reject.',
        ], $this->authHeader());

        $response->assertNotFound();
    }

    public function test_reject_unauthenticated(): void
    {
        $response = $this->postJson("/api/v1/ep/{$this->epId}/ec/reject", [
            'remarks' => 'Test.',
        ]);

        $response->assertUnauthorized();
    }

    // ═══════════════════════════════════════════════════════════════
    // EC HISTORY
    // ═══════════════════════════════════════════════════════════════

    public function test_ec_history_returns_ordered_records(): void
    {
        $response = $this->getJson("/api/v1/ep/{$this->epId}/ec/history", $this->authHeader());

        $response->assertOk()
            ->assertJsonPath('success', true);

        $history = $response->json('data');
        $this->assertGreaterThanOrEqual(1, count($history));

        // The only seeded record
        $this->assertEquals('DRAFT', $history[0]['new_status']);
        $this->assertNull($history[0]['previous_status']);
    }

    public function test_ec_history_after_reject(): void
    {
        // Reject the EC
        $this->postJson("/api/v1/ep/{$this->epId}/ec/reject", [
            'remarks' => 'For history test.',
        ], $this->authHeader());

        $response = $this->getJson("/api/v1/ep/{$this->epId}/ec/history", $this->authHeader());

        $response->assertOk();

        $history = $response->json('data');
        $this->assertCount(2, $history);

        $this->assertEquals('DRAFT', $history[0]['new_status']);
        $this->assertEquals('REJECTED', $history[1]['new_status']);
        $this->assertEquals('DRAFT', $history[1]['previous_status']);
    }

    public function test_ec_history_empty_for_no_ec(): void
    {
        $noEcEp = DB::table('affected_person')->insertGetId([
            'project_id' => $this->projectId,
            'ep_id' => 'NEH'.rand(1000, 9999),
            'name' => 'No EC History',
            'nid_number' => (string) rand(1000000000, 9999999999),
            'mobile' => '017'.rand(10000000, 99999999),
            'gender_id' => 1,
            'district_id' => $this->districtId,
            'upazila_id' => $this->upazilaId,
            'land_status_id' => 1,
            'rc_type_id' => 1,
            'monthly_income' => '5000.00',
            'monthly_expense' => '3000.00',
            'status' => 'DRAFT',
            'is_household_head' => 1,
        ]);

        $response = $this->getJson("/api/v1/ep/{$noEcEp}/ec/history", $this->authHeader());

        $response->assertNotFound();
    }

    public function test_ec_history_unauthenticated(): void
    {
        $response = $this->getJson("/api/v1/ep/{$this->epId}/ec/history");

        $response->assertUnauthorized();
    }
}
