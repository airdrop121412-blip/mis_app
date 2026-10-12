<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Isolated authorization test for permission middleware.
 *
 * This test creates all entities via direct DB inserts to avoid any
 * session cookie contamination from login flows.
 */
class PermissionMiddlewareTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Verify that a user with NO roles gets 403 on a permission-gated endpoint.
     */
    public function test_user_without_role_gets_403_on_ep_index(): void
    {
        // 1. Create a user with NO roles — direct DB insert, no login
        $user = User::factory()->create([
            'username' => 'noperm_iso_'.fake()->unique()->numerify('#####'),
            'password_hash' => bcrypt('TestPass@123'),
        ]);

        // Verify: user has no roles
        $roleCount = DB::table('user_role')->where('user_id', $user->user_id)->count();
        $this->assertEquals(0, $roleCount, 'User should have no roles');

        // 2. Create a Sanctum token — direct DB insert, no login
        $plainTextToken = Str::random(60);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->user_id,
            'name' => 'test',
            'token' => hash('sha256', $plainTextToken),
            'abilities' => json_encode(['*']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Verify: this specific token exists (table may have other tokens from seed data)
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->user_id,
            'token' => hash('sha256', $plainTextToken),
        ]);

        // 3. Make request — ONLY Bearer token, no session cookies exist
        $response = $this->withHeader('Authorization', 'Bearer '.$plainTextToken)
            ->getJson('/api/v1/ep');

        $response->assertStatus(403);
    }

    /**
     * Verify that a user with NO roles gets 403 on ep.submit.
     * This is the exact scenario that previously returned 200.
     *
     * IMPORTANT: All test data is created via direct DB inserts to avoid
     * session cookie contamination from the test HTTP client's cookie jar.
     * When a prior postJson() call is made, the response may set a session
     * cookie. Subsequent requests carry that cookie, causing
     * EnsureFrontendRequestsAreStateful to activate session-based auth,
     * which overrides Bearer token auth and resolves to the WRONG user.
     */
    public function test_user_without_role_gets_403_on_ep_submit(): void
    {
        // 1. Create a no-perm user — direct DB only
        $noPermUser = User::factory()->create([
            'username' => 'noperm_submit_'.fake()->unique()->numerify('#####'),
            'password_hash' => bcrypt('TestPass@123'),
        ]);

        $nopermToken = Str::random(60);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $noPermUser->user_id,
            'name' => 'noperm-test',
            'token' => hash('sha256', $nopermToken),
            'abilities' => json_encode(['*']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Verify: no-perm user has no roles
        $roleCount = DB::table('user_role')->where('user_id', $noPermUser->user_id)->count();
        $this->assertEquals(0, $roleCount, 'User should have no roles');

        // 2. Create EP entirely via direct DB inserts — NO HTTP request
        $districtId = DB::table('district')->insertGetId([
            'name' => 'Submit Test District '.Str::random(5),
            'code' => (string) rand(10, 99),
            'is_active' => 1,
        ]);
        $upazilaId = DB::table('upazila')->insertGetId([
            'name' => 'Submit Test Upazila '.Str::random(5),
            'code' => (string) rand(10, 99),
            'district_id' => $districtId,
            'is_active' => 1,
        ]);
        $projectId = DB::table('project')->insertGetId([
            'name' => 'Submit Test Project',
            'project_code' => 'STP-'.Str::random(5),
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'is_active' => 1,
        ]);

        // Insert EP directly — status DRAFT (required for submit transition)
        $epId = DB::table('affected_person')->insertGetId([
            'project_id' => $projectId,
            'ep_id' => 'TESTSUBMIT01DRAFT',
            'name' => 'Test Submit EP',
            'nid_number' => '1122334455',
            'mobile' => '01711223344',
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

        // Insert initial status history (no created_at — schema has no timestamps)
        DB::table('status_history')->insert([
            'entity_type' => 'App\\Models\\AffectedPerson',
            'entity_id' => $epId,
            'previous_status' => null,
            'new_status' => 'DRAFT',
            'changed_by' => $noPermUser->user_id,
            'remarks' => 'Created',
        ]);

        // 3. Attempt submit with no-perm Bearer token — must get 403
        // NOTE: First HTTP request in this test, so no session cookie exists
        $response = $this->withHeader('Authorization', 'Bearer '.$nopermToken)
            ->postJson("/api/v1/ep/{$epId}/submit");

        $response->assertStatus(403);
    }

    /**
     * Verify that a user WITH the ep.submit permission can submit.
     * This proves the permission middleware actually resolves correctly.
     */
    public function test_user_with_submit_permission_can_submit(): void
    {
        // Create admin user with all permissions — direct DB only
        $admin = User::factory()->create([
            'username' => 'admin_submit_'.fake()->unique()->numerify('#####'),
            'password_hash' => bcrypt('AdminPass@123'),
        ]);
        DB::table('user_role')->insert([
            'user_id' => $admin->user_id,
            'role_id' => 1, // ADMIN
        ]);

        $adminToken = Str::random(60);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $admin->user_id,
            'name' => 'admin-test',
            'token' => hash('sha256', $adminToken),
            'abilities' => json_encode(['*']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create EP entirely via direct DB inserts
        $districtId = DB::table('district')->insertGetId([
            'name' => 'Submit Test District '.Str::random(5),
            'code' => (string) rand(10, 99),
            'is_active' => 1,
        ]);
        $upazilaId = DB::table('upazila')->insertGetId([
            'name' => 'Submit Test Upazila '.Str::random(5),
            'code' => (string) rand(10, 99),
            'district_id' => $districtId,
            'is_active' => 1,
        ]);
        $projectId = DB::table('project')->insertGetId([
            'name' => 'Submit Test Project',
            'project_code' => 'STP-'.Str::random(5),
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'is_active' => 1,
        ]);

        $epId = DB::table('affected_person')->insertGetId([
            'project_id' => $projectId,
            'ep_id' => 'TESTSUBMITADMIN01',
            'name' => 'Admin Submit EP',
            'nid_number' => '9876543210',
            'mobile' => '01798765432',
            'gender_id' => 1,
            'district_id' => $districtId,
            'upazila_id' => $upazilaId,
            'land_status_id' => 1,
            'rc_type_id' => 1,
            'monthly_income' => '8000.00',
            'monthly_expense' => '4000.00',
            'status' => 'DRAFT',
            'is_household_head' => 1,
        ]);

        DB::table('status_history')->insert([
            'entity_type' => 'App\\Models\\AffectedPerson',
            'entity_id' => $epId,
            'previous_status' => null,
            'new_status' => 'DRAFT',
            'changed_by' => $admin->user_id,
            'remarks' => 'Created',
        ]);

        // Submit with admin token — should succeed (admin has ep.submit)
        // This is the first HTTP request, so no session cookie exists
        $response = $this->withHeader('Authorization', 'Bearer '.$adminToken)
            ->postJson("/api/v1/ep/{$epId}/submit");

        $response->assertOk();
    }
}
