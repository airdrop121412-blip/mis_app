<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * role — RBAC role (Phase 3 seed: ADMIN, SURVEYOR, VERIFIER, APPROVER,
 * PAYMENT_OFFICER, REPORT_VIEWER). Referenced by code, never by numeric id,
 * in application logic (seed guide §2).
 */
class Role extends BaseModel
{
    protected $table = 'role';

    protected $primaryKey = 'role_id';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permission', 'role_id', 'permission_id')
            ->withPivot(['role_permission_id', 'remarks']);
    }

    public function rolePermissions(): HasMany
    {
        return $this->hasMany(RolePermission::class, 'role_id', 'role_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_role', 'role_id', 'user_id')
            ->withPivot(['user_role_id', 'remarks']);
    }

    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class, 'role_id', 'role_id');
    }
}
