<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * infrastructure_type — infrastructure classification master.
 */
class InfrastructureType extends BaseModel
{
    protected $table = 'infrastructure_type';

    protected $primaryKey = 'infrastructure_type_id';

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
        return $this->hasMany(Infrastructure::class, 'infrastructure_type_id', 'infrastructure_type_id');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(InfrastructureRate::class, 'infrastructure_type_id', 'infrastructure_type_id');
    }
}
