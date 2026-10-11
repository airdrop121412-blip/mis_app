<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * land_damage_type — e.g. PARTIAL / FULL.
 */
class LandDamageType extends BaseModel
{
    protected $table = 'land_damage_type';

    protected $primaryKey = 'land_damage_type_id';

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

    public function landAssessments(): HasMany
    {
        return $this->hasMany(LandAssessment::class, 'damage_type_id', 'land_damage_type_id');
    }
}
