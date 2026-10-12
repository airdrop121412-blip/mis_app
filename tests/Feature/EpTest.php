<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * EP (Affected Person) CRUD + status transition tests (architecture §5.4).
 * Uses DatabaseTransactions — never RefreshDatabase.
 */
class EpTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Create user, assign seeded ADMIN role (role_id=1), login, return Bearer token.
     */
    private function adminToken(): string
    {
        $user = User::factory()->create([
            'username' => 'ep_admin_'.fake()->unique()->numerify('#####'),
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

    /**
     * Create user with NO roles and return a token.
     */
    private function noPermToken(): string
    {
        $user = User::factory()->create([
            'username' => 'ep_noperm_'.fake()->unique()->numerify('#####'),
            'password_hash' => bcrypt('SecretPass@123'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $user->username,
            'password' => 'SecretPass@123',
        ]);

        return $response->json('data.token');
    }

    /**
     * Insert seed FK rows needed for an EP and return an array of IDs.
     */
    private function seedEpFks(): array
    {
        // Districts are NOT seeded — create one with a short unique code.
        $uniqueCode = (string) rand(10, 99);
        $districtId = DB::table('district')->insertGetId([
            'name' => 'EP Test District '.$uniqueCode,
            'code' => $uniqueCode,
            'is_active' => 1,
        ]);

        $upazilaCode = (string) rand(10, 99);
        $upazilaId = DB::table('upazila')->insertGetId([
            'name' => 'EP Test Upazila '.$upazilaCode,
            'code' => $upazilaCode,
            'district_id' => $districtId,
            'is_active' => 1,
        ]);

        // These are seeded — use existing IDs to avoid unique constraint violations.
        $genderId = 1;        // MALE
        $landStatusId = 1;    // TITLE
        $rcTypeId = 1;        // RESIDENTIAL

        return compact('districtId', 'upazilaId', 'genderId', 'landStatusId', 'rcTypeId');
    }

    /**
     * Create a project and return its ID.
     */
    private function createProject(): int
    {
        return DB::table('project')->insertGetId([
            'name' => 'EP Test Project',
            'project_code' => 'EP-TP',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'is_active' => 1,
        ]);
    }

    /**
     * Create a single EP and return the full response JSON.
     */
    private function createEp(string $token, int $projectId, array $fks, string $nid = '1234567890'): array
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ep', [
                'project_id' => $projectId,
                'name' => 'Test EP Person',
                'nid_number' => $nid,
                'mobile' => '01712345678',
                'gender_id' => $fks['genderId'],
                'district_id' => $fks['districtId'],
                'upazila_id' => $fks['upazilaId'],
                'land_status_id' => $fks['landStatusId'],
                'rc_type_id' => $fks['rcTypeId'],
                'monthly_income' => '5000.00',
                'monthly_expense' => '3000.00',
            ]);

        return $response->json();
    }

    // ── LIST ─────────────────────────────────────────────────────────

    public function test_list_ep_returns_paginated(): void
    {
        $token = $this->adminToken();
        $projectId = $this->createProject();
        $fks = $this->seedEpFks();
        $this->createEp($token, $projectId, $fks);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/ep')
            ->assertOk()
            ->assertJsonStructure([
                'success',
                'data',
                'meta' => ['pagination'],
            ]);
    }

    public function test_list_ep_filter_by_project_id(): void
    {
        $token = $this->adminToken();
        $projectId = $this->createProject();
        $fks = $this->seedEpFks();
        $this->createEp($token, $projectId, $fks);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/ep?project_id={$projectId}")
            ->assertOk()
            ->assertJsonPath('data.0.project_id', $projectId);
    }

    public function test_list_ep_filter_by_status(): void
    {
        $token = $this->adminToken();
        $projectId = $this->createProject();
        $fks = $this->seedEpFks();
        $this->createEp($token, $projectId, $fks);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/ep?status=DRAFT')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'DRAFT');
    }

    public function test_list_ep_search_by_name(): void
    {
        $token = $this->adminToken();
        $projectId = $this->createProject();
        $fks = $this->seedEpFks();
        $this->createEp($token, $projectId, $fks);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/ep?q=Test+EP')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Test EP Person');
    }

    // ── CREATE ───────────────────────────────────────────────────────

    public function test_create_ep_with_generated_id(): void
    {
        $token = $this->adminToken();
        $projectId = $this->createProject();
        $fks = $this->seedEpFks();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ep', [
                'project_id' => $projectId,
                'name' => 'Created EP',
                'nid_number' => '1111111111',
                'mobile' => '01798765432',
                'gender_id' => $fks['genderId'],
                'district_id' => $fks['districtId'],
                'upazila_id' => $fks['upazilaId'],
                'land_status_id' => $fks['landStatusId'],
                'rc_type_id' => $fks['rcTypeId'],
                'monthly_income' => '8000.00',
                'monthly_expense' => '4000.00',
            ])
            ->assertCreated()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'affected_person_id',
                    'ep_id',
                    'name',
                    'status',
                ],
            ]);

        // EP ID matches pattern: district(2) + upazila(2) + landStatus + rcType + '#' + serial(4)
        $this->assertMatchesRegularExpression(
            '/^\d{2}\d{2}[A-Z]+[A-Z]+#\d{4}$/',
            $response['data']['ep_id']
        );

        // Status always starts as DRAFT
        $this->assertEquals('DRAFT', $response['data']['status']);
    }

    public function test_create_ep_stores_status_history(): void
    {
        $token = $this->adminToken();
        $projectId = $this->createProject();
        $fks = $this->seedEpFks();
        $created = $this->createEp($token, $projectId, $fks);
        $epId = $created['data']['affected_person_id'];

        $history = DB::table('status_history')
            ->where('entity_type', 'EP')
            ->where('entity_id', $epId)
            ->get();

        $this->assertCount(1, $history);
        $this->assertEquals('DRAFT', $history->first()->new_status);
        $this->assertNull($history->first()->previous_status);
    }

    public function test_create_ep_with_invalid_nid_rejected(): void
    {
        $token = $this->adminToken();
        $projectId = $this->createProject();
        $fks = $this->seedEpFks();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ep', [
                'project_id' => $projectId,
                'name' => 'Bad NID',
                'nid_number' => '12345', // too short
                'mobile' => '01712345678',
                'gender_id' => $fks['genderId'],
                'district_id' => $fks['districtId'],
                'upazila_id' => $fks['upazilaId'],
                'land_status_id' => $fks['landStatusId'],
                'rc_type_id' => $fks['rcTypeId'],
                'monthly_income' => '5000.00',
                'monthly_expense' => '3000.00',
            ])
            ->assertUnprocessable();
    }

    public function test_create_ep_missing_required_fields_rejected(): void
    {
        $token = $this->adminToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ep', [
                'name' => 'Incomplete EP',
            ])
            ->assertUnprocessable();
    }

    public function test_duplicate_nid_within_project_rejected(): void
    {
        $token = $this->adminToken();
        $projectId = $this->createProject();
        $fks = $this->seedEpFks();

        // First EP succeeds
        $this->createEp($token, $projectId, $fks, '1234567890');

        // Second EP with same NID in same project fails
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ep', [
                'project_id' => $projectId,
                'name' => 'Duplicate NID EP',
                'nid_number' => '1234567890',
                'mobile' => '01711122233',
                'gender_id' => $fks['genderId'],
                'district_id' => $fks['districtId'],
                'upazila_id' => $fks['upazilaId'],
                'land_status_id' => $fks['landStatusId'],
                'rc_type_id' => $fks['rcTypeId'],
                'monthly_income' => '4000.00',
                'monthly_expense' => '2000.00',
            ])
            ->assertUnprocessable();
    }

    public function test_same_nid_in_different_project_succeeds(): void
    {
        $token = $this->adminToken();
        $fks = $this->seedEpFks();

        $project1 = $this->createProject();
        $project2 = DB::table('project')->insertGetId([
            'name' => 'Second Project',
            'project_code' => 'EP-TP2',
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'is_active' => 1,
        ]);

        $this->createEp($token, $project1, $fks, '1234567890');

        // Same NID in a different project is fine
        $this->createEp($token, $project2, $fks, '1234567890');
    }

    // ── SHOW ─────────────────────────────────────────────────────────

    public function test_show_ep_returns_full_record(): void
    {
        $token = $this->adminToken();
        $projectId = $this->createProject();
        $fks = $this->seedEpFks();
        $created = $this->createEp($token, $projectId, $fks);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/ep/{$created['data']['affected_person_id']}")
            ->assertOk()
            ->assertJsonPath('data.ep_id', $created['data']['ep_id']);
    }

    // ── UPDATE ───────────────────────────────────────────────────────

    public function test_update_draft_ep_succeeds(): void
    {
        $token = $this->adminToken();
        $projectId = $this->createProject();
        $fks = $this->seedEpFks();
        $created = $this->createEp($token, $projectId, $fks);
        $epId = $created['data']['affected_person_id'];

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/ep/{$epId}", [
                'name' => 'Updated EP Name',
                'monthly_income' => '9000.00',
            ])
            ->assertOk();

        $this->assertDatabaseHas('affected_person', [
            'affected_person_id' => $epId,
            'name' => 'Updated EP Name',
            'monthly_income' => '9000.00',
        ]);
    }

    public function test_update_submitted_ep_rejected(): void
    {
        $token = $this->adminToken();
        $projectId = $this->createProject();
        $fks = $this->seedEpFks();
        $created = $this->createEp($token, $projectId, $fks);
        $epId = $created['data']['affected_person_id'];

        // Submit first
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/ep/{$epId}/submit")
            ->assertOk();

        // Now try to edit — should fail
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/ep/{$epId}", [
                'name' => 'Hacker',
            ])
            ->assertUnprocessable();
    }

    public function test_update_verified_ep_rejected(): void
    {
        $token = $this->adminToken();
        $projectId = $this->createProject();
        $fks = $this->seedEpFks();
        $created = $this->createEp($token, $projectId, $fks);
        $epId = $created['data']['affected_person_id'];

        // Submit → Verify
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/ep/{$epId}/submit")->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/ep/{$epId}/verify")->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/ep/{$epId}", [
                'name' => 'Hacker',
            ])
            ->assertUnprocessable();
    }

    // ── DELETE ───────────────────────────────────────────────────────

    public function test_delete_draft_ep_succeeds(): void
    {
        $token = $this->adminToken();
        $projectId = $this->createProject();
        $fks = $this->seedEpFks();
        $created = $this->createEp($token, $projectId, $fks);
        $epId = $created['data']['affected_person_id'];

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/ep/{$epId}")
            ->assertOk();

        $this->assertDatabaseMissing('affected_person', [
            'affected_person_id' => $epId,
        ]);
    }

    public function test_delete_submitted_ep_rejected(): void
    {
        $token = $this->adminToken();
        $projectId = $this->createProject();
        $fks = $this->seedEpFks();
        $created = $this->createEp($token, $projectId, $fks);
        $epId = $created['data']['affected_person_id'];

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/ep/{$epId}/submit")
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/ep/{$epId}")
            ->assertUnprocessable();
    }

    // ── STATUS TRANSITIONS ───────────────────────────────────────────

    public function test_full_lifecycle_draft_to_approved(): void
    {
        $token = $this->adminToken();
        $projectId = $this->createProject();
        $fks = $this->seedEpFks();
        $created = $this->createEp($token, $projectId, $fks);
        $epId = $created['data']['affected_person_id'];

        // SUBMIT
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/ep/{$epId}/submit")
            ->assertOk();
        $this->assertDatabaseHas('affected_person', [
            'affected_person_id' => $epId,
            'status' => 'SUBMITTED',
        ]);

        // VERIFY
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/ep/{$epId}/verify")
            ->assertOk();
        $this->assertDatabaseHas('affected_person', [
            'affected_person_id' => $epId,
            'status' => 'VERIFIED',
        ]);

        // APPROVE
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/ep/{$epId}/approve")
            ->assertOk();
        $this->assertDatabaseHas('affected_person', [
            'affected_person_id' => $epId,
            'status' => 'APPROVED',
        ]);
    }

    public function test_invalid_transition_draft_to_verified_rejected(): void
    {
        $token = $this->adminToken();
        $projectId = $this->createProject();
        $fks = $this->seedEpFks();
        $created = $this->createEp($token, $projectId, $fks);
        $epId = $created['data']['affected_person_id'];

        // DRAFT → VERIFIED (skip SUBMITTED) — should fail
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/ep/{$epId}/verify")
            ->assertUnprocessable();
    }

    public function test_invalid_transition_draft_to_approved_rejected(): void
    {
        $token = $this->adminToken();
        $projectId = $this->createProject();
        $fks = $this->seedEpFks();
        $created = $this->createEp($token, $projectId, $fks);
        $epId = $created['data']['affected_person_id'];

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/ep/{$epId}/approve")
            ->assertUnprocessable();
    }

    public function test_status_history_recorded_on_submit(): void
    {
        $token = $this->adminToken();
        $projectId = $this->createProject();
        $fks = $this->seedEpFks();
        $created = $this->createEp($token, $projectId, $fks);
        $epId = $created['data']['affected_person_id'];

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/ep/{$epId}/submit")
            ->assertOk();

        $history = DB::table('status_history')
            ->where('entity_type', 'EP')
            ->where('entity_id', $epId)
            ->get();

        // 2 entries: DRAFT (initial create) + SUBMITTED
        $this->assertCount(2, $history);
        $this->assertEquals('SUBMITTED', $history->last()->new_status);
    }

    public function test_full_status_history_after_complete_lifecycle(): void
    {
        $token = $this->adminToken();
        $projectId = $this->createProject();
        $fks = $this->seedEpFks();
        $created = $this->createEp($token, $projectId, $fks);
        $epId = $created['data']['affected_person_id'];

        // Full cycle: submit → verify → approve
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/ep/{$epId}/submit")->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/ep/{$epId}/verify")->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/ep/{$epId}/approve")->assertOk();

        $history = DB::table('status_history')
            ->where('entity_type', 'EP')
            ->where('entity_id', $epId)
            ->orderBy('status_history_id')
            ->get();

        // 4 entries: DRAFT → SUBMITTED → VERIFIED → APPROVED
        $this->assertCount(4, $history);
        $this->assertEquals('DRAFT', $history[0]->new_status);
        $this->assertEquals('SUBMITTED', $history[1]->new_status);
        $this->assertEquals('VERIFIED', $history[2]->new_status);
        $this->assertEquals('APPROVED', $history[3]->new_status);
    }

    // ── PERMISSION GATING ────────────────────────────────────────────

    public function test_unauthenticated_user_gets_401(): void
    {
        $this->getJson('/api/v1/ep')
            ->assertUnauthorized();
    }

    public function test_user_without_permission_gets_403(): void
    {
        $token = $this->noPermToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/ep')
            ->assertForbidden();
    }

    public function test_user_without_permission_cannot_create(): void
    {
        $token = $this->noPermToken();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/ep', [
                'project_id' => 1,
                'name' => 'Should Fail',
            ])
            ->assertForbidden();
    }

    // ── File Data (document generation) ────────────────────────────

    public function test_file_data_returns_ep_with_relationships(): void
    {
        $token = $this->adminToken();
        $projectId = $this->createProject();
        $fks = $this->seedEpFks();

        // Create EP
        $epId = DB::table('affected_person')->insertGetId([
            'project_id' => $projectId,
            'ep_id' => 'FILE-TEST-001',
            'name' => 'File Data Test Person',
            'nid_number' => '9988776655',
            'mobile' => '01712345678',
            'gender_id' => $fks['genderId'],
            'district_id' => $fks['districtId'],
            'upazila_id' => $fks['upazilaId'],
            'land_status_id' => $fks['landStatusId'],
            'rc_type_id' => $fks['rcTypeId'],
            'monthly_income' => '50000.00',
            'monthly_expense' => '30000.00',
            'status' => 'DRAFT',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/ep/{$epId}/file-data");

        $response->assertOk()
            ->assertJsonPath('data.affected_person_id', $epId)
            ->assertJsonPath('data.ep_id', 'FILE-TEST-001')
            ->assertJsonPath('data.name', 'File Data Test Person');

        // Verify nested arrays exist
        $data = $response->json('data');
        $this->assertArrayHasKey('lands', $data);
        $this->assertArrayHasKey('trees', $data);
        $this->assertArrayHasKey('infrastructures', $data);
        $this->assertArrayHasKey('tenants', $data);
        $this->assertArrayHasKey('family_members', $data);
        $this->assertArrayHasKey('workers', $data);
    }

    public function test_file_data_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/v1/ep/1/file-data')
            ->assertUnauthorized();
    }

    public function test_file_data_no_permission_returns_403(): void
    {
        $token = $this->noPermToken();
        $projectId = $this->createProject();
        $fks = $this->seedEpFks();

        $epId = DB::table('affected_person')->insertGetId([
            'project_id' => $projectId,
            'ep_id' => 'FILE-PERM-001',
            'name' => 'Permission Test Person',
            'nid_number' => '5566778899',
            'mobile' => '01712345678',
            'gender_id' => $fks['genderId'],
            'district_id' => $fks['districtId'],
            'upazila_id' => $fks['upazilaId'],
            'land_status_id' => $fks['landStatusId'],
            'rc_type_id' => $fks['rcTypeId'],
            'monthly_income' => '50000.00',
            'monthly_expense' => '30000.00',
            'status' => 'DRAFT',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/ep/{$epId}/file-data")
            ->assertForbidden();
    }
}
