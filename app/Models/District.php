<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * district — administrative geography master.
 */
class District extends BaseModel
{
    protected $table = 'district';

    protected $primaryKey = 'district_id';

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

    public function upazilas(): HasMany
    {
        return $this->hasMany(Upazila::class, 'district_id', 'district_id');
    }

    public function affectedPersons(): HasMany
    {
        return $this->hasMany(AffectedPerson::class, 'district_id', 'district_id');
    }
}
