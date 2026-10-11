<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * land_status — e.g. TITLE / NON_TITLE.
 */
class LandStatus extends BaseModel
{
    protected $table = 'land_status';

    protected $primaryKey = 'land_status_id';

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
        return $this->hasMany(AffectedPerson::class, 'land_status_id', 'land_status_id');
    }

    public function lands(): HasMany
    {
        return $this->hasMany(Land::class, 'land_status_id', 'land_status_id');
    }
}
