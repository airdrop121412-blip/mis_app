<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Seed the ENTRY_OPERATOR role and grant it its workflow scope:
 * master.view, ep.create, ep.edit, ep.view, report.view.
 *
 * Idempotent: safe to re-run and tolerant of a differently-ordered
 * permission table (permissions are looked up by code, not by id).
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $permissionCodes = [
        'master.view',
        'ep.create',
        'ep.edit',
        'ep.view',
        'report.view',
    ];

    public function up(): void
    {
        $roleId = DB::table('role')->where('code', 'ENTRY_OPERATOR')->value('role_id');

        if ($roleId === null) {
            $roleId = DB::table('role')->insertGetId([
                'code' => 'ENTRY_OPERATOR',
                'name' => 'Entry Operator',
                'description' => 'Data entry: create/edit/view EP surveys and view reports.',
                'is_active' => 1,
                'remarks' => 'System-wide role',
            ]);
        }

        $permissionIds = DB::table('permission')
            ->whereIn('code', $this->permissionCodes)
            ->pluck('permission_id');

        foreach ($permissionIds as $permissionId) {
            $bound = DB::table('role_permission')
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->exists();

            if (! $bound) {
                DB::table('role_permission')->insert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }

        foreach (DB::table('user_role')->where('role_id', $roleId)->pluck('user_id') as $userId) {
            Cache::forget("user:{$userId}:permissions");
        }
    }

    public function down(): void
    {
        $roleId = DB::table('role')->where('code', 'ENTRY_OPERATOR')->value('role_id');

        if ($roleId === null) {
            return;
        }

        $userIds = DB::table('user_role')->where('role_id', $roleId)->pluck('user_id');

        DB::table('role_permission')->where('role_id', $roleId)->delete();
        DB::table('user_role')->where('role_id', $roleId)->delete();
        DB::table('role')->where('role_id', $roleId)->delete();

        foreach ($userIds as $userId) {
            Cache::forget("user:{$userId}:permissions");
        }
    }
};
