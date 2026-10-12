<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Master CRUD endpoints (architecture §5.2).
 * Uses DatabaseTransactions — never RefreshDatabase.
 */
class MasterTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Create a user, assign the seeded ADMIN role (all 32 permissions),
     * and return a valid Bearer token for that user.
     */
    private function adminToken(): string
    {
        $user = User::factory()->create([
            'username' => 'master_admin_'.fake()->unique()->numerify('#####'),
            'password_hash' => bcrypt('SecretPass@123'),
        ]);

        // ADMIN role (id=1) is seeded and visible inside the transaction.
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
     * Create a user with NO roles (no permissions) and return a token.
     */
    private function noPermToken(): string
    {
        $user = User::factory()->create([
            'username' => 'master_noperm_'.fake()->unique()->numerify('#####'),
            'password_hash' => bcrypt('SecretPass@123'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => $user->username,
            'password' => 'SecretPass@123',
        ]);

        return $response->json('data.token');
    }

    // ── READ (master.view) ────────────────────────────────────────

    public function test_list_gender_returns_paginated(): void
    {
        $token = $this->adminToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/masters/gender');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [0 => ['id', 'code', 'name', 'is_active']],
                'meta' => ['pagination' => ['current_page', 'total']],
            ]);
    }

    public function test_show_gender_by_id(): void
    {
        $token = $this->adminToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/masters/gender/1'); // MALE (seeded)

        $response->assertOk()
            ->assertJsonPath('data.code', 'MALE')
            ->assertJsonPath('data.is_active', true);
    }

    public function test_list_with_search_filter(): void
    {
        $token = $this->adminToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/masters/gender?q=FEMALE');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'FEMALE');
    }

    public function test_list_with_is_active_filter(): void
    {
        $token = $this->adminToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/masters/gender?is_active=1');

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_upazila_list_filtered_by_district_id(): void
    {
        $token = $this->adminToken();

        // District 1 is seeded (DHAKA).
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/masters/upazila?district_id=1');

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_village_list_filtered_by_upazila_id(): void
    {
        $token = $this->adminToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/masters/village?upazila_id=1');

        $response->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_unknown_entity_returns_404(): void
    {
        $token = $this->adminToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/masters/nonexistent_table');

        $response->assertStatus(404);
    }

    // ── CREATE (master.manage) ────────────────────────────────────

    public function test_create_gender(): void
    {
        $token = $this->adminToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/masters/gender', [
                'code' => 'OTHER_TEST',
                'name' => 'Other (Test)',
                'is_active' => true,
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'OTHER_TEST')
            ->assertJsonPath('data.name', 'Other (Test)')
            ->assertHeader('Location');

        // Verify it's readable.
        $id = $response->json('data.id');
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/masters/gender/{$id}")
            ->assertOk()
            ->assertJsonPath('data.code', 'OTHER_TEST');
    }

    public function test_create_village_without_code(): void
    {
        $token = $this->adminToken();

        // District/upazila are not seeded — create the FK chain first.
        $districtRes = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/masters/district', [
                'code' => 'VTEST_D',
                'name' => 'Village Test District',
            ]);
        $districtId = $districtRes->json('data.id');

        $upazilaRes = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/masters/upazila', [
                'code' => 'VTEST_U',
                'name' => 'Village Test Upazila',
                'district_id' => $districtId,
            ]);
        $upazilaId = $upazilaRes->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/masters/village', [
                'upazila_id' => $upazilaId,
                'name' => 'Test Village '.fake()->unique()->numerify('###'),
                'is_active' => true,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', $response->json('data.name'))
            ->assertJsonPath('data.is_active', true);
    }

    public function test_create_rejects_duplicate_code(): void
    {
        $token = $this->adminToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/masters/gender', [
                'code' => 'MALE', // Already seeded
                'name' => 'Duplicate Male',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    public function test_create_rejects_duplicate_name(): void
    {
        $token = $this->adminToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/masters/gender', [
                'code' => 'UNIQUE_CODE',
                'name' => 'Male', // Already seeded
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_create_requires_code_and_name(): void
    {
        $token = $this->adminToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/masters/gender', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code', 'name']);
    }

    // ── UPDATE (master.manage) ────────────────────────────────────

    public function test_update_gender(): void
    {
        $token = $this->adminToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/masters/gender/1', [
                'name' => 'Male (Updated)',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Male (Updated)')
            ->assertJsonPath('data.code', 'MALE'); // Code unchanged.

        // Restore original name for other tests.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/masters/gender/1', ['name' => 'Male']);
    }

    public function test_update_rejects_unique_code_collision(): void
    {
        $token = $this->adminToken();

        // Try to rename FEMALE's code to MALE (taken by id=1).
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/masters/gender/2', [
                'code' => 'MALE',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    // ── DEACTIVATE (master.manage) ────────────────────────────────

    public function test_deactivate_gender(): void
    {
        $token = $this->adminToken();

        // First create a disposable record to deactivate.
        $create = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/masters/gender', [
                'code' => 'DEACT_TEST',
                'name' => 'Deactivate Me',
            ]);
        $id = $create->json('data.id');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/masters/gender/{$id}");

        $response->assertOk()
            ->assertJsonPath('success', true);

        // Verify is_active = 0.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/masters/gender/{$id}")
            ->assertJsonPath('data.is_active', false);
    }

    public function test_deactivate_already_inactive_returns_422(): void
    {
        $token = $this->adminToken();

        // Create then deactivate.
        $create = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/masters/gender', [
                'code' => 'INACT_TEST',
                'name' => 'Already Inactive',
            ]);
        $id = $create->json('data.id');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/masters/gender/{$id}")
            ->assertOk();

        // Second deactivate → 422.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/v1/masters/gender/{$id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    // ── PERMISSION GATING ─────────────────────────────────────────

    public function test_unauthenticated_returns_401(): void
    {
        $response = $this->getJson('/api/v1/masters/gender');
        $response->assertStatus(401);
    }

    public function test_user_without_permission_returns_403(): void
    {
        $token = $this->noPermToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/masters/gender');

        $response->assertStatus(403);
    }

    public function test_manage_endpoint_requires_master_manage(): void
    {
        $token = $this->noPermToken();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/masters/gender', [
                'code' => 'X',
                'name' => 'X',
            ]);

        $response->assertStatus(403);
    }
}
