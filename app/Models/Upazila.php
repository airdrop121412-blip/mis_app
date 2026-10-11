<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * upazila — sub-district, belongs to a district.
 */
class Upazila extends BaseModel
{
    protected $table = 'upazila';

    protected $primaryKey = 'upazila_id';

    /** @var list<string> */
    protected $fillable = [
        'district_id',
        'code',
        'name',
        'is_active',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_id', 'district_id');
    }

    public function villages(): HasMany
    {
        return $this->hasMany(Village::class, 'upazila_id', 'upazila_id');
    }

    public function affectedPersons(): HasMany
    {
        return $this->hasMany(AffectedPerson::class, 'upazila_id', 'upazila_id');
    }
}
