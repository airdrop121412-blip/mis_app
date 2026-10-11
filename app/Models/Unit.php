<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * unit — measurement unit master (DECIMAL / SQFT / ...).
 */
class Unit extends BaseModel
{
    protected $table = 'unit';

    protected $primaryKey = 'unit_id';

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

    public function landRates(): HasMany
    {
        return $this->hasMany(LandRate::class, 'unit_id', 'unit_id');
    }

    public function cropRates(): HasMany
    {
        return $this->hasMany(CropRate::class, 'unit_id', 'unit_id');
    }
}
