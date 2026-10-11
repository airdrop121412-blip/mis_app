<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * usage_type — usage classification for infrastructure.
 */
class UsageType extends BaseModel
{
    protected $table = 'usage_type';

    protected $primaryKey = 'usage_type_id';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'is_active',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function infrastructures(): HasMany
    {
        return $this->hasMany(Infrastructure::class, 'usage_type_id', 'usage_type_id');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(InfrastructureRate::class, 'usage_type_id', 'usage_type_id');
    }
}
