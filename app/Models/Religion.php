<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * religion — person master.
 */
class Religion extends BaseModel
{
    protected $table = 'religion';

    protected $primaryKey = 'religion_id';

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
        return $this->hasMany(AffectedPerson::class, 'religion_id', 'religion_id');
    }
}
