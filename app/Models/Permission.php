<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * permission — RBAC permission code (32 seeded, `<module>.<action>`).
 */
class Permission extends BaseModel
{
    protected $table = 'permission';

    protected $primaryKey = 'permission_id';

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

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permission', 'permission_id', 'role_id')
            ->withPivot(['role_permission_id', 'remarks']);
    }

    public function rolePermissions(): HasMany
    {
        return $this->hasMany(RolePermission::class, 'permission_id', 'permission_id');
    }
}
