<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Authentication + RBAC resolution (architecture §2, §3).
 *
 * The permission matrix is resolved PER REQUEST (never frozen into a token)
 * and cached per user. The file cache driver uses a plain per-user key; when
 * Redis is enabled the same key can move to a tagged cache with no schema
 * change.
 */
class AuthService
{
    public const FORCE_CHANGE_MARKER = 'TEMP_PASSWORD_FORCE_CHANGE';

    /**
     * Validate credentials against the authoritative `users` table.
     * Returns the user on success, null on any failure (unknown/inactive
     * user, wrong password).
     */
    public function attempt(string $username, string $password): ?User
    {
        $user = User::query()->where('username', $username)->first();

        if (! $user || ! $user->isActive()) {
            return null;
        }

        if (! Hash::check($password, $user->password_hash)) {
            return null;
        }

        return $user;
    }

    /**
     * The user's distinct permission codes, resolved in ONE query
     * (user_role -> role -> role_permission -> permission) and cached per user.
     *
     * @return list<string>
     */
    public function userPermissions(User $user): array
    {
        $key = "user:{$user->user_id}:permissions";

        return Cache::remember($key, 3600, function () use ($user) {
            return DB::table('permission')
                ->join('role_permission', 'role_permission.permission_id', '=', 'permission.permission_id')
                ->join('role', 'role.role_id', '=', 'role_permission.role_id')
                ->join('user_role', 'user_role.role_id', '=', 'role.role_id')
                ->where('user_role.user_id', $user->user_id)
                ->where('permission.is_active', 1)
                ->where('role.is_active', 1)
                ->distinct()
                ->orderBy('permission.code')
                ->pluck('permission.code')
                ->all();
        });
    }

    public function hasPermission(User $user, string $permission): bool
    {
        return in_array($permission, $this->userPermissions($user), true);
    }

    /**
     * Invalidate a user's cached permission set — call whenever roles or
     * role->permission assignments change (architecture §3).
     */
    public function forgetUserPermissions(User $user): void
    {
        Cache::forget("user:{$user->user_id}:permissions");
    }

    /**
     * True while the first-login force-change marker is present.
     */
    public function mustChangePassword(User $user): bool
    {
        return $user->remarks !== null
            && str_contains($user->remarks, self::FORCE_CHANGE_MARKER);
    }

    /**
     * Remove the force-change marker from remarks (no schema change).
     */
    public function markPasswordChanged(User $user): void
    {
        $remarks = str_replace(self::FORCE_CHANGE_MARKER, '', (string) $user->remarks);
        $remarks = trim(preg_replace('/\s*\|\s*/', ' | ', $remarks), ' |');

        $user->forceFill(['remarks' => $remarks === '' ? null : $remarks])->save();
    }
}
