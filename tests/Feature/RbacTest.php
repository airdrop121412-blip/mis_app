<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Read-only RBAC verification against the Phase 3 seeded master data.
 * Uses DatabaseTransactions (never RefreshDatabase) — no seed data is modified.
 *
 * Assertions that depend on mutable local-dev state (the live admin password,
 * the force-change marker) are asserted against the SEED CONTRACT or against
 * transactional probe users instead, so the suite stays green as the local dev
 * database evolves.
 */
class RbacTest extends TestCase
{
    use DatabaseTransactions;

    private function auth(): AuthService
    {
        return app(AuthService::class);
    }

    public function test_seeded_admin_hash_authenticates_and_bad_credentials_fail(): void
    {
        // The PHASE3 seed (Section D) stores a legacy "$2b$" bcrypt hash for the
        // documented dev password. Assert against the seed's literal hash so the
        // test does not depend on the mutable local dev password, then prove the
        // full attempt() flow accepts that legacy prefix and rejects bad input.
        $seededHash = '$2b$10$e0m7dFamhdIiGf.x7h4OaunXVm4Wh4FuxDwhTXy9hzA4GryA/Sxq6';
        $this->assertTrue(
            Hash::check('ChangeMe@123', $seededHash),
            'Legacy $2b$ seed hash must verify against the documented dev password.'
        );

        $probe = User::factory()->create([
            'username' => 'rbac_hash_probe_'.fake()->unique()->numerify('#####'),
            'password_hash' => $seededHash,
        ]);

        $this->assertInstanceOf(User::class, $this->auth()->attempt($probe->username, 'ChangeMe@123'));
        $this->assertNull($this->auth()->attempt($probe->username, 'WrongPassword@999'));
        $this->assertNull(
            $this->auth()->attempt('no_such_user_'.fake()->unique()->numerify('#####'), 'ChangeMe@123')
        );
    }

    public function test_seeded_admin_resolves_admin_role_via_relationships(): void
    {
        $admin = User::query()->where('username', 'admin')->firstOrFail();

        $this->assertTrue($admin->is_active);
        $this->assertSame(['ADMIN'], $admin->roles()->pluck('role.code')->all());

        // Force-change marker machinery — asserted on a transactional probe user
        // because the live dev admin legitimately lost its marker when the
        // password was last changed through the app.
        $probe = User::factory()->create([
            'username' => 'rbac_marker_probe_'.fake()->unique()->numerify('#####'),
            'remarks' => 'DEV_TEST_ONLY|TEMP_PASSWORD_FORCE_CHANGE | probe',
        ]);

        $this->assertTrue($probe->mustChangePassword());
        $this->auth()->markPasswordChanged($probe);
        $this->assertFalse($probe->fresh()->mustChangePassword());
    }

    public function test_seeded_admin_resolves_all_permissions_via_auth_service(): void
    {
        $admin = User::query()->where('username', 'admin')->firstOrFail();

        $permissions = $this->auth()->userPermissions($admin);

        // 32 PHASE3 permissions + ep.import (migration 2026_09_06_000004) = 33.
        $this->assertCount(33, $permissions, 'ADMIN role is granted all 33 permission codes.');
        $this->assertContains('master.view', $permissions);
        $this->assertContains('role.manage', $permissions);
        $this->assertContains('ep.view', $permissions);
        $this->assertContains('ep.import', $permissions);
        $this->assertContains('voucher.pay', $permissions);
        $this->assertTrue($this->auth()->hasPermission($admin, 'master.view'));
        $this->assertFalse($this->auth()->hasPermission($admin, 'nonexistent.code'));
    }

    public function test_admin_role_permission_mapping_is_consistent(): void
    {
        $adminRole = Role::query()->where('code', 'ADMIN')->firstOrFail();

        // 32 seeded grants + ep.import grant = 33.
        $this->assertSame(33, $adminRole->permissions()->count());
        $this->assertSame(1, $adminRole->users()->count());
    }
}
