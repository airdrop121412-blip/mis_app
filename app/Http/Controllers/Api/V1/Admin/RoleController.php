<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Role management (architecture §5.12 #80–82).
 *
 * Endpoints:
 *   GET  /api/v1/roles              — list roles with permissions (user.manage)
 *   POST /api/v1/roles              — create role (role.manage)
 *   POST /api/v1/roles/{id}/permissions — assign permissions to role (role.manage)
 */
class RoleController extends Controller
{
    /**
     * GET /api/v1/roles
     */
    public function index(): JsonResponse
    {
        $roles = Role::with('permissions:permission_id,code,name')
            ->orderBy('role_id')
            ->get();

        return ApiResponse::success($roles, 'OK');
    }

    /**
     * POST /api/v1/roles
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:30|unique:role,code',
            'name' => 'required|string|max:100|unique:role,name',
            'description' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'remarks' => 'nullable|string|max:255',
        ]);

        $role = Role::create($validated);

        return ApiResponse::created($role, 'Role created');
    }

    /**
     * POST /api/v1/roles/{id}/permissions
     *
     * Body: { "permission_ids": [1, 2, 3] }
     *
     * Replaces all existing permissions for the role (full sync).
     */
    public function assignPermissions(Request $request, int $id): JsonResponse
    {
        $role = Role::findOrFail($id);

        $validated = $request->validate([
            'permission_ids' => 'nullable|array',
            'permission_ids.*' => 'integer|exists:permission,permission_id',
        ]);

        $permissionIds = $validated['permission_ids'];

        // Remove existing
        DB::table('role_permission')
            ->where('role_id', $id)
            ->delete();

        // Insert new
        if (! empty($permissionIds)) {
            $rows = array_map(
                fn (int $pid) => ['role_id' => $id, 'permission_id' => $pid],
                $permissionIds,
            );
            DB::table('role_permission')->insert($rows);
        }

        // Invalidate permission cache for all users with this role.
        // Without this, revoked permissions remain valid for up to 1 hour.
        $userIds = DB::table('user_role')
            ->where('role_id', $id)
            ->pluck('user_id');

        foreach ($userIds as $userId) {
            Cache::forget("user:{$userId}:permissions");
        }

        return ApiResponse::success(
            $role->load('permissions'),
            'Permissions updated',
        );
    }
}
