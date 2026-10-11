<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Add the `ep.import` permission and grant it to the ADMIN role.
 *
 * Gates the Excel bulk-upload endpoint; only admins (role code 'ADMIN') can
 * download the import template and upload EP data. Admin permission caches are
 * flushed so the change takes effect without manual re-login.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permission')->where('code', 'ep.import')->value('permission_id');

        if ($permissionId === null) {
            $permissionId = DB::table('permission')->insertGetId([
                'code' => 'ep.import',
                'name' => 'EP Import',
                'description' => 'Upload EP records from an Excel file.',
                'is_active' => 1,
            ]);
        }

        $roleId = DB::table('role')->where('code', 'ADMIN')->value('role_id');

        if ($roleId !== null) {
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

            foreach (DB::table('user_role')->where('role_id', $roleId)->pluck('user_id') as $userId) {
                Cache::forget("user:{$userId}:permissions");
            }
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permission')->where('code', 'ep.import')->value('permission_id');

        if ($permissionId !== null) {
            DB::table('role_permission')->where('permission_id', $permissionId)->delete();
            DB::table('permission')->where('permission_id', $permissionId)->delete();
        }
    }
};
