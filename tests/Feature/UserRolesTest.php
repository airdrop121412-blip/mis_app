<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * User & Role management tests (architecture §5.12).
 *
 * Covers: UserController (index, store, update, assign-role)
 *         RoleController (index, store, assign-permissions)
 *
 * Uses direct DB inserts for all test data to avoid session contamination.
 */
class UserRolesTest extends TestCase
{
    use DatabaseTransactions;

    private string $token;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        // Admin user with user.manage + role.manage permissions
        $this->userId = DB::table('users')->insertGetId([
            'username' => 'ur_admin_'.Str::random(8),
            'full_name' => 'UR Admin',
            'password_hash' => bcrypt('AdminPass@123'),
            'is_active' => 1,
        ]);

        // Assign ADMIN role (role_id = 1)
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
    }

    private function authHeader(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    // ═══════════════════════════════════════════════════════════════
    // USER INDEX
    // ═══════════════════════════════════════════════════════════════

    public function test_index_returns_paginated_users(): void
    {
        $response = $this->getJson('/api/v1/users', $this->authHeader());

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'data' => [
                        ['user_id', 'username', 'full_name', 'roles'],
                    ],
                ],
            ]);
    }

    public function test_index_search_filters_by_username(): void
    {
        // Create a distinct user
        $uid = DB::table('users')->insertGetId([
            'username' => 'searchable_xyz',
            'full_name' => 'Search Person',
            'password_hash' => bcrypt('x'),
            'is_active' => 1,
        ]);

        $response = $this->getJson('/api/v1/users?q=searchable_xyz', $this->authHeader());

        $response->assertOk();
        $usernames = collect($response->json('data.data'))->pluck('username')->toArray();
        $this->assertContains('searchable_xyz', $usernames);
        $this->assertNotContains('searchable_xyz', array_diff($usernames, ['searchable_xyz']));
    }

    public function test_index_filter_by_is_active(): void
    {
        // Create inactive user
        DB::table('users')->insert([
            'username' => 'inactive_xyz_'.Str::random(5),
            'full_name' => 'Inactive',
            'password_hash' => bcrypt('x'),
            'is_active' => 0,
        ]);

        $response = $this->getJson('/api/v1/users?is_active=0', $this->authHeader());

        $response->assertOk();
        $users = collect($response->json('data.data'));
        $this->assertTrue($users->every(fn ($u) => $u['is_active'] === false || $u['is_active'] === 0));
    }

    // ═══════════════════════════════════════════════════════════════
    // USER STORE
    // ═══════════════════════════════════════════════════════════════

    public function test_store_creates_user_with_temp_password(): void
    {
        $response = $this->postJson('/api/v1/users', [
            'username' => 'new_user_'.Str::random(6),
            'full_name' => 'New User',
            'email' => Str::random(8).'@test.com',
            'mobile' => '01712345678',
        ], $this->authHeader());

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'user' => ['user_id', 'username', 'full_name'],
                    'temp_password',
                ],
            ]);

        // Verify temp_password is a non-empty string
        $tempPassword = $response->json('data.temp_password');
        $this->assertNotEmpty($tempPassword);
        $this->assertIsString($tempPassword);

        // Verify the user's remarks contain the force-change marker
        $userId = $response->json('data.user.user_id');
        $user = DB::table('users')->where('user_id', $userId)->first();
        $this->assertStringContainsString('TEMP_PASSWORD_FORCE_CHANGE', $user->remarks);
    }

    public function test_store_validates_unique_username(): void
    {
        $username = 'dup_user_'.Str::random(6);

        DB::table('users')->insert([
            'username' => $username,
            'full_name' => 'Original',
            'password_hash' => bcrypt('x'),
            'is_active' => 1,
        ]);

        $response = $this->postJson('/api/v1/users', [
            'username' => $username,
            'full_name' => 'Duplicate',
        ], $this->authHeader());

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['username']);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->postJson('/api/v1/users', [], $this->authHeader());

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['username', 'full_name']);
    }

    // ═══════════════════════════════════════════════════════════════
    // USER UPDATE
    // ═══════════════════════════════════════════════════════════════

    public function test_update_modifies_user_fields(): void
    {
        $userId = DB::table('users')->insertGetId([
            'username' => 'upd_user_'.Str::random(6),
            'full_name' => 'Old Name',
            'password_hash' => bcrypt('x'),
            'is_active' => 1,
        ]);

        $response = $this->putJson("/api/v1/users/{$userId}", [
            'full_name' => 'New Name',
            'mobile' => '01812345678',
        ], $this->authHeader());

        $response->assertOk()
            ->assertJsonPath('data.full_name', 'New Name')
            ->assertJsonPath('data.mobile', '01812345678');

        // Verify in DB
        $user = DB::table('users')->where('user_id', $userId)->first();
        $this->assertEquals('New Name', $user->full_name);
    }

    public function test_update_404_for_nonexistent_user(): void
    {
        $response = $this->putJson('/api/v1/users/999999', [
            'full_name' => 'Ghost',
        ], $this->authHeader());

        $response->assertNotFound();
    }

    // ═══════════════════════════════════════════════════════════════
    // ASSIGN ROLE
    // ═══════════════════════════════════════════════════════════════

    public function test_assign_role_adds_role_to_user(): void
    {
        $userId = DB::table('users')->insertGetId([
            'username' => 'role_user_'.Str::random(6),
            'full_name' => 'Role User',
            'password_hash' => bcrypt('x'),
            'is_active' => 1,
        ]);

        // SURVEYOR role_id = 2 (seeded)
        $response = $this->postJson("/api/v1/users/{$userId}/assign-role", [
            'role_id' => 2,
        ], $this->authHeader());

        $response->assertOk()
            ->assertJsonPath('success', true);

        // Verify in DB
        $exists = DB::table('user_role')
            ->where('user_id', $userId)
            ->where('role_id', 2)
            ->exists();
        $this->assertTrue($exists);
    }

    public function test_assign_role_duplicate_rejected(): void
    {
        $userId = DB::table('users')->insertGetId([
            'username' => 'dup_role_'.Str::random(6),
            'full_name' => 'Dup Role',
            'password_hash' => bcrypt('x'),
            'is_active' => 1,
        ]);

        // Already has ADMIN role
        DB::table('user_role')->insert([
            'user_id' => $userId,
            'role_id' => 1,
        ]);

        $response = $this->postJson("/api/v1/users/{$userId}/assign-role", [
            'role_id' => 1,
        ], $this->authHeader());

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_assign_role_404_for_nonexistent_user(): void
    {
        $response = $this->postJson('/api/v1/users/999999/assign-role', [
            'role_id' => 1,
        ], $this->authHeader());

        $response->assertNotFound();
    }

    public function test_assign_role_validates_role_exists(): void
    {
        $userId = DB::table('users')->insertGetId([
            'username' => 'vr_user_'.Str::random(6),
            'full_name' => 'VR User',
            'password_hash' => bcrypt('x'),
            'is_active' => 1,
        ]);

        $response = $this->postJson("/api/v1/users/{$userId}/assign-role", [
            'role_id' => 999999,
        ], $this->authHeader());

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['role_id']);
    }

    // ═══════════════════════════════════════════════════════════════
    // ROLE INDEX
    // ═══════════════════════════════════════════════════════════════

    public function test_role_index_returns_roles_with_permissions(): void
    {
        $response = $this->getJson('/api/v1/roles', $this->authHeader());

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    ['role_id', 'code', 'name', 'permissions'],
                ],
            ]);

        // Should have at least 6 seeded roles
        $this->assertGreaterThanOrEqual(6, count($response->json('data')));
    }

    // ═══════════════════════════════════════════════════════════════
    // ROLE STORE
    // ═══════════════════════════════════════════════════════════════

    public function test_role_store_creates_role(): void
    {
        $response = $this->postJson('/api/v1/roles', [
            'code' => 'TEST_ROLE_'.strtoupper(Str::random(5)),
            'name' => 'Test Role '.Str::random(5),
            'description' => 'A test role',
        ], $this->authHeader());

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => ['role_id', 'code', 'name'],
            ]);
    }

    public function test_role_store_validates_unique_code(): void
    {
        $response = $this->postJson('/api/v1/roles', [
            'code' => 'ADMIN',
            'name' => 'Duplicate Admin',
        ], $this->authHeader());

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    // ═══════════════════════════════════════════════════════════════
    // ROLE ASSIGN PERMISSIONS
    // ═══════════════════════════════════════════════════════════════

    public function test_assign_permissions_syncs_role_permissions(): void
    {
        // Get two permission IDs
        $perms = DB::table('permission')->limit(3)->pluck('permission_id')->toArray();

        // Create a new role
        $roleId = DB::table('role')->insertGetId([
            'code' => 'PERM_TEST_'.strtoupper(Str::random(5)),
            'name' => 'Perm Test '.Str::random(5),
            'is_active' => 1,
        ]);

        $response = $this->postJson("/api/v1/roles/{$roleId}/permissions", [
            'permission_ids' => $perms,
        ], $this->authHeader());

        $response->assertOk()
            ->assertJsonPath('success', true);

        // Verify count in DB
        $count = DB::table('role_permission')
            ->where('role_id', $roleId)
            ->count();
        $this->assertEquals(3, $count);
    }

    public function test_assign_permissions_replaces_existing(): void
    {
        $roleId = DB::table('role')->insertGetId([
            'code' => 'REPLACE_TEST_'.strtoupper(Str::random(5)),
            'name' => 'Replace Test '.Str::random(5),
            'is_active' => 1,
        ]);

        // Add an initial permission
        $perm1 = DB::table('permission')->value('permission_id');
        DB::table('role_permission')->insert([
            'role_id' => $roleId,
            'permission_id' => $perm1,
        ]);

        // Replace with a different permission
        $perm2 = DB::table('permission')->where('permission_id', '!=', $perm1)->value('permission_id');

        $response = $this->postJson("/api/v1/roles/{$roleId}/permissions", [
            'permission_ids' => [$perm2],
        ], $this->authHeader());

        $response->assertOk();

        // Only the new permission should exist
        $perms = DB::table('role_permission')
            ->where('role_id', $roleId)
            ->pluck('permission_id')
            ->toArray();
        $this->assertEquals([$perm2], $perms);
    }

    public function test_assign_permissions_empty_array_clears_all(): void
    {
        $roleId = DB::table('role')->insertGetId([
            'code' => 'CLEAR_TEST_'.strtoupper(Str::random(5)),
            'name' => 'Clear Test '.Str::random(5),
            'is_active' => 1,
        ]);

        // Add a permission
        $perm = DB::table('permission')->value('permission_id');
        DB::table('role_permission')->insert([
            'role_id' => $roleId,
            'permission_id' => $perm,
        ]);

        $response = $this->postJson("/api/v1/roles/{$roleId}/permissions", [
            'permission_ids' => [],
        ], $this->authHeader());

        $response->assertOk();

        $count = DB::table('role_permission')
            ->where('role_id', $roleId)
            ->count();
        $this->assertEquals(0, $count);
    }

    public function test_assign_permissions_404_for_nonexistent_role(): void
    {
        $response = $this->postJson('/api/v1/roles/999999/permissions', [
            'permission_ids' => [1],
        ], $this->authHeader());

        $response->assertNotFound();
    }

    public function test_assign_permissions_validates_permission_exists(): void
    {
        $roleId = DB::table('role')->insertGetId([
            'code' => 'VP_TEST_'.strtoupper(Str::random(5)),
            'name' => 'VP Test '.Str::random(5),
            'is_active' => 1,
        ]);

        $response = $this->postJson("/api/v1/roles/{$roleId}/permissions", [
            'permission_ids' => [999999],
        ], $this->authHeader());

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['permission_ids.0']);
    }

    // ═══════════════════════════════════════════════════════════════
    // AUTH / PERMISSION
    // ═══════════════════════════════════════════════════════════════

    public function test_unauthenticated_returns_401(): void
    {
        $response = $this->getJson('/api/v1/users');

        $response->assertUnauthorized();
    }

    public function test_no_permission_returns_403(): void
    {
        // Create user with SURVEYOR role (no user.manage permission)
        $surveyorId = DB::table('users')->insertGetId([
            'username' => 'surv_no_perm_'.Str::random(6),
            'full_name' => 'Surveyor',
            'password_hash' => bcrypt('x'),
            'is_active' => 1,
        ]);
        DB::table('user_role')->insert([
            'user_id' => $surveyorId,
            'role_id' => 2, // SURVEYOR
        ]);

        $token = Str::random(60);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $surveyorId,
            'name' => 'test',
            'token' => hash('sha256', $token),
            'abilities' => json_encode(['*']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/users', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertForbidden();
    }
}
