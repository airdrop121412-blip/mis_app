<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * residential_commercial_type — RESIDENTIAL / COMMERCIAL (PK rc_type_id).
 */
class ResidentialCommercialType extends BaseModel
{
    protected $table = 'residential_commercial_type';

    protected $primaryKey = 'rc_type_id';

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
        return $this->hasMany(AffectedPerson::class, 'rc_type_id', 'rc_type_id');
    }
}
