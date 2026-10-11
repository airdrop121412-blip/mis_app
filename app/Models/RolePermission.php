<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * role_permission — RBAC mapping pivot (surrogate PK role_permission_id;
 * UNIQUE(role_id, permission_id)). 76 seeded rows.
 */
class RolePermission extends BaseModel
{
    protected $table = 'role_permission';

    protected $primaryKey = 'role_permission_id';

    /** @var list<string> */
    protected $fillable = [
        'role_id',
        'permission_id',
        'remarks',
    ];

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id', 'role_id');
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class, 'permission_id', 'permission_id');
    }
}
