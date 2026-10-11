<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * users — RBAC actor table (Phase 2 schema; NOT Laravel's default users table).
 *
 * PK: user_id  |  password stored in password_hash  |  no timestamps.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'users';

    protected $primaryKey = 'user_id';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'username',
        'password_hash',
        'full_name',
        'designation',
        'email',
        'mobile',
        'is_active',
        'remarks',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password_hash',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * The authoritative schema names the credential column password_hash.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    /**
     * Roles assigned through the user_role pivot (surrogate PK user_role_id).
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_role', 'user_id', 'role_id')
            ->withPivot(['user_role_id', 'remarks']);
    }

    /**
     * Raw user_role rows (useful for role assignment audit).
     */
    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class, 'user_id', 'user_id');
    }

    /**
     * Status-history rows attributed to this user (FK changed_by, SET NULL).
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(StatusHistory::class, 'changed_by', 'user_id');
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    /**
     * True while the first-login force-change marker is present in remarks
     * (architecture §2.2 / §2.3; seed Section D).
     */
    public function mustChangePassword(): bool
    {
        return $this->remarks !== null
            && str_contains($this->remarks, 'TEMP_PASSWORD_FORCE_CHANGE');
    }
}
