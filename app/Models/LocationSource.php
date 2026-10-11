<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * location_source — GPS_DEVICE / MANUAL / MAP_CLICK / ... master.
 */
class LocationSource extends BaseModel
{
    protected $table = 'location_source';

    protected $primaryKey = 'location_source_id';

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

    public function affectedPersons(): HasMany
    {
        return $this->hasMany(AffectedPerson::class, 'location_source_id', 'location_source_id');
    }
}
