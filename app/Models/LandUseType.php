<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * land_use_type — land usage classification master.
 */
class LandUseType extends BaseModel
{
    protected $table = 'land_use_type';

    protected $primaryKey = 'land_use_type_id';

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

    public function lands(): HasMany
    {
        return $this->hasMany(Land::class, 'land_use_type_id', 'land_use_type_id');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(LandRate::class, 'land_use_type_id', 'land_use_type_id');
    }
}
