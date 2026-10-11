<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * land_type — land classification master.
 */
class LandType extends BaseModel
{
    protected $table = 'land_type';

    protected $primaryKey = 'land_type_id';

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
        return $this->hasMany(Land::class, 'land_type_id', 'land_type_id');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(LandRate::class, 'land_type_id', 'land_type_id');
    }
}
