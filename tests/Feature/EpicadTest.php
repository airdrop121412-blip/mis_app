<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EPICAD (EP Card) tests — architecture §5.2.
 *
 * All test data created via direct DB inserts to avoid session contamination.
 */
class EpicadTest extends TestCase
{
    use DatabaseTransactions;

    private string $token;

    private int $userId;

    private int $projectId;

    private int $epId;

    protected function setUp(): void
    {
        parent::setUp();

        // Create admin user + token via direct DB
        $this->userId = DB::table('users')->insertGetId([
            'username' => 'epicad_admin_'.Str::random(8),
            'full_name' => 'Epicad Admin',
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

        // Create project
        $this->projectId = DB::table('project')->insertGetId([
            'name' => 'Epicad Test Project',
            'project_code' => 'ETP-'.Str::random(5),
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'is_active' => 1,
        ]);

        // Create EP in SUBMITTED status
        $districtId = DB::table('district')->insertGetId([
            'name' => 'Epicad District '.Str::random(5),
            'code' => (string) rand(10, 99),
            'is_active' => 1,
        ]);
        $upazilaId = DB::table('upazila')->insertGetId([
            'name' => 'Epicad Upazila '.Str::random(5),
            'code' => (string) rand(10, 99),
            'district_id' => $districtId,
            'is_active' => 1,
        ]);

        $this->epId = DB::table('affected_person')->insertGetId([
            'project_id' => $this->projectId,
            'ep_id' => 'EPICAD'.rand(1000, 9999),
            'name' => 'Epicad Test Person',
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
            'remarks' => 'Submitted for testing',
            'changed_at' => now(),
        ]);
    }

    // ── Tests ────────────────────────────────────────────────────

    public function test_show_returns_404_when_no_epicad(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson("/api/v1/ep/{$this->epId}/epicad");

        $response->assertStatus(404);
    }

    public function test_create_epicad_when_ep_is_submitted(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/epicad");

        $response->assertCreated();
        $response->assertJsonPath('data.affected_person_id', $this->epId);
        $response->assertJsonPath('data.print_status', 'NOT_PRINTED');
    }

    public function test_create_epicad_when_ep_is_draft_is_rejected(): void
    {
        // Create a DRAFT EP
        $draftEpId = $this->createDraftEp();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$draftEpId}/epicad");

        $response->assertStatus(422);
    }

    public function test_create_duplicate_epicad_is_rejected(): void
    {
        // Create first EPICAD
        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/epicad")
            ->assertCreated();

        // Try to create second
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/epicad");

        $response->assertStatus(422);
    }

    public function test_update_epicad_when_not_printed(): void
    {
        $this->createEpicad();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson("/api/v1/ep/{$this->epId}/epicad", [
                'remarks' => 'Updated remarks',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.remarks', 'Updated remarks');
    }

    public function test_update_epicad_when_printed_is_rejected(): void
    {
        $this->createEpicad();
        $this->printEpicad('PRINTED');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson("/api/v1/ep/{$this->epId}/epicad", [
                'remarks' => 'Should fail',
            ]);

        $response->assertStatus(422);
    }

    public function test_delete_epicad_when_not_printed(): void
    {
        $this->createEpicad();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->deleteJson("/api/v1/ep/{$this->epId}/epicad");

        $response->assertOk();

        // Verify deleted
        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson("/api/v1/ep/{$this->epId}/epicad")
            ->assertStatus(404);
    }

    public function test_delete_epicad_when_printed_is_rejected(): void
    {
        $this->createEpicad();
        $this->printEpicad('PRINTED');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->deleteJson("/api/v1/ep/{$this->epId}/epicad");

        $response->assertStatus(422);
    }

    public function test_print_epicad_not_printed_to_printed(): void
    {
        $this->createEpicad();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/epicad/print");

        $response->assertOk();
        $response->assertJsonPath('data.print_status', 'PRINTED');
    }

    public function test_print_epicad_printed_to_reprinted(): void
    {
        $this->createEpicad();
        $this->printEpicad('PRINTED');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/epicad/print");

        $response->assertOk();
        $response->assertJsonPath('data.print_status', 'REPRINTED');
    }

    public function test_print_epicad_from_reprinted_is_rejected(): void
    {
        $this->createEpicad();
        $this->printEpicad('REPRINTED');

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/epicad/print");

        $response->assertStatus(422);
    }

    public function test_unauthenticated_gets_401(): void
    {
        $response = $this->postJson("/api/v1/ep/{$this->epId}/epicad");

        $response->assertStatus(401);
    }

    public function test_no_permission_gets_403(): void
    {
        $noPermToken = $this->createNoPermToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$noPermToken)
            ->postJson("/api/v1/ep/{$this->epId}/epicad");

        $response->assertStatus(403);
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function createEpicad(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/epicad")
            ->assertCreated();
    }

    private function printEpicad(string $targetStatus): void
    {
        $count = match ($targetStatus) {
            'PRINTED' => 1,
            'REPRINTED' => 2,
            default => 0,
        };

        for ($i = 0; $i < $count; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$this->token)
                ->postJson("/api/v1/ep/{$this->epId}/epicad/print")
                ->assertOk();
        }
    }

    private function createDraftEp(): int
    {
        $districtId = DB::table('district')->insertGetId([
            'name' => 'Draft District '.Str::random(5),
            'code' => (string) rand(10, 99),
            'is_active' => 1,
        ]);
        $upazilaId = DB::table('upazila')->insertGetId([
            'name' => 'Draft Upazila '.Str::random(5),
            'code' => (string) rand(10, 99),
            'district_id' => $districtId,
            'is_active' => 1,
        ]);

        return DB::table('affected_person')->insertGetId([
            'project_id' => $this->projectId,
            'ep_id' => 'DRAFT'.rand(1000, 9999),
            'name' => 'Draft Person',
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
