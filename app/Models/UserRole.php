<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * user_role — RBAC user→role pivot (surrogate PK user_role_id;
 * UNIQUE(user_id, role_id)).
 */
class UserRole extends BaseModel
{
    protected $table = 'user_role';

    protected $primaryKey = 'user_role_id';

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'role_id',
        'remarks',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id', 'role_id');
    }
}
