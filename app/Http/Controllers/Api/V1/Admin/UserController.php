<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * User management (architecture §5.12 #76–79).
 *
 * Endpoints:
 *   GET    /api/v1/users           — list users (user.manage)
 *   POST   /api/v1/users           — create user + temp password (user.manage)
 *   PUT    /api/v1/users/{id}      — update user (user.manage)
 *   DELETE /api/v1/users/{id}      — delete user (user.manage)
 *   POST   /api/v1/users/{id}/assign-role — assign role (user.manage)
 *
 * Temp password: random 12-char string; remarks set to TEMP_PASSWORD_FORCE_CHANGE
 * so the first-login middleware forces a password reset (architecture §2.2).
 */
class UserController extends Controller
{
    /**
     * GET /api/v1/users
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::with('roles:role_id,code,name')
            ->orderBy('user_id');

        // Search by username, full_name, designation, email, mobile
        if ($q = $request->input('q')) {
            $query->where(function ($sub) use ($q) {
                $sub->where('username', 'LIKE', "%{$q}%")
                    ->orWhere('full_name', 'LIKE', "%{$q}%")
                    ->orWhere('designation', 'LIKE', "%{$q}%")
                    ->orWhere('email', 'LIKE', "%{$q}%")
                    ->orWhere('mobile', 'LIKE', "%{$q}%");
            });
        }

        // Filter by is_active
        if ($request->has('is_active')) {
            $query->where('is_active', (int) $request->boolean('is_active'));
        }

        $users = $query->paginate($request->integer('per_page', 25));

        return ApiResponse::success($users, 'OK');
    }

    /**
     * POST /api/v1/users
     *
     * Creates a user with a random temp password and marks remarks for
     * force-change on first login.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => 'required|string|max:50|unique:users,username',
            'full_name' => 'required|string|max:100',
            'designation' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:150|unique:users,email',
            'mobile' => 'nullable|string|max:15',
            'password' => [
                'nullable',
                'string',
                'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
            'is_active' => 'boolean',
            'remarks' => 'nullable|string|max:255',
        ]);

        // Admin may set the password directly; otherwise a random temp
        // password is generated and the first-login force-change marker set.
        $usesTempPassword = empty($validated['password']);
        $password = $usesTempPassword ? Str::random(12) : $validated['password'];

        $user = User::create([
            'username' => $validated['username'],
            'password_hash' => Hash::make($password),
            'full_name' => $validated['full_name'],
            'designation' => $validated['designation'] ?? null,
            'email' => $validated['email'] ?? null,
            'mobile' => $validated['mobile'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'remarks' => $usesTempPassword
                ? 'TEMP_PASSWORD_FORCE_CHANGE'
                : ($validated['remarks'] ?? null),
        ]);

        return ApiResponse::created([
            'user' => $user->load('roles'),
            'temp_password' => $usesTempPassword ? $password : null,
        ], 'User created');
    }

    /**
     * PUT /api/v1/users/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'full_name' => 'sometimes|string|max:100',
            'designation' => 'nullable|string|max:100',
            'email' => 'nullable|email|max:150|unique:users,email,'.$id.',user_id',
            'mobile' => 'nullable|string|max:15',
            'password' => [
                'nullable',
                'string',
                'confirmed',
                Password::min(12)->mixedCase()->numbers()->symbols(),
            ],
            'is_active' => 'boolean',
            'remarks' => 'nullable|string|max:255',
        ]);

        // Don't allow updating username via this endpoint
        $updates = collect($validated)->only([
            'full_name', 'designation', 'email', 'mobile', 'is_active', 'remarks',
        ])->toArray();

        if (! empty($validated['password'])) {
            $updates['password_hash'] = Hash::make($validated['password']);

            // Clear a pending first-login force-change marker.
            if (! array_key_exists('remarks', $validated) && $user->mustChangePassword()) {
                $updates['remarks'] = null;
            }
        }

        $user->update($updates);

        if (! empty($validated['password'])) {
            Cache::forget("user:{$id}:permissions");
        }

        return ApiResponse::success($user->load('roles'), 'User updated');
    }

    /**
     * DELETE /api/v1/users/{id}
     *
     * Hard-deletes a user after clearing role assignments. The currently
     * authenticated user cannot delete their own account. Other tables that
     * reference the user (status_history.changed_by, epicad_design.created_by/
     * updated_by) are nullable and unconstrained, so the row is removed safely.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($request->user()?->user_id === $id) {
            return ApiResponse::error(
                'You cannot delete your own account.',
                'SELF_DELETE_FORBIDDEN',
                422,
            );
        }

        $user = User::findOrFail($id);

        DB::table('user_role')->where('user_id', $id)->delete();

        $user->delete();

        // Drop any cached permission set for the removed user.
        Cache::forget("user:{$id}:permissions");

        return ApiResponse::success(null, 'User deleted');
    }

    /**
     * POST /api/v1/users/{id}/assign-role
     *
     * Body: { "role_id": int }
     *
     * Replaces the user's role assignment with the given single role
     * (one role per user).
     */
    public function assignRole(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'role_id' => 'required|integer|exists:role,role_id',
        ]);

        DB::table('user_role')->where('user_id', $id)->delete();

        DB::table('user_role')->insert([
            'user_id' => $id,
            'role_id' => $validated['role_id'],
        ]);

        // Invalidate permission cache so the new role's permissions take effect immediately.
        Cache::forget("user:{$id}:permissions");

        return ApiResponse::success(
            $user->load('roles'),
            'Role assigned',
        );
    }
}
