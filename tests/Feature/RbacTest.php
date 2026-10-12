<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Read-only RBAC verification against the Phase 3 seeded master data.
 * Uses DatabaseTransactions (never RefreshDatabase) — no seed data is modified.
 */
class RbacTest extends TestCase
{
    use DatabaseTransactions;

    private function auth(): AuthService
    {
        return app(AuthService::class);
    }

    public function test_seeded_admin_can_authenticate_with_dev_password(): void
    {
        // Phase 3 seeds the admin hash with a legacy "$2b$" prefix; Hash::check must
        // accept it (config/hashing.php sets verify=false). Wrong passwords still fail.
        // NOTE: Admin password was reset to Admin@123 during Phase 4B-8 validation.
        $this->assertInstanceOf(User::class, $this->auth()->attempt('admin', 'Admin@123'));
        $this->assertNull($this->auth()->attempt('admin', 'WrongPassword@999'));
        $this->assertNull($this->auth()->attempt('no_such_user', 'Admin@123'));
    }

    public function test_seeded_admin_resolves_admin_role_via_relationships(): void
    {
        $admin = User::query()->where('username', 'admin')->firstOrFail();

        $this->assertTrue($admin->is_active);
        $this->assertTrue($admin->mustChangePassword(), 'Admin must carry the force-change marker.');
        $this->assertSame(['ADMIN'], $admin->roles()->pluck('role.code')->all());
    }

    public function test_seeded_admin_resolves_all_permissions_via_auth_service(): void
    {
        $admin = User::query()->where('username', 'admin')->firstOrFail();

        $permissions = $this->auth()->userPermissions($admin);

        $this->assertCount(32, $permissions, 'ADMIN role is granted all 32 permission codes.');
        $this->assertContains('master.view', $permissions);
        $this->assertContains('role.manage', $permissions);
        $this->assertContains('ep.view', $permissions);
        $this->assertContains('voucher.pay', $permissions);
        $this->assertTrue($this->auth()->hasPermission($admin, 'master.view'));
        $this->assertFalse($this->auth()->hasPermission($admin, 'nonexistent.code'));
    }

    public function test_admin_role_permission_mapping_is_consistent(): void
    {
        $adminRole = Role::query()->where('code', 'ADMIN')->firstOrFail();

        $this->assertSame(32, $adminRole->permissions()->count());
        $this->assertSame(1, $adminRole->users()->count());
    }
}
