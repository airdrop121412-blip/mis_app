<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * occupation — person master.
 */
class Occupation extends BaseModel
{
    protected $table = 'occupation';

    protected $primaryKey = 'occupation_id';

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
        return $this->hasMany(AffectedPerson::class, 'occupation_id', 'occupation_id');
    }

    public function familyMembers(): HasMany
    {
        return $this->hasMany(FamilyMember::class, 'occupation_id', 'occupation_id');
    }

    public function workers(): HasMany
    {
        return $this->hasMany(Worker::class, 'occupation_id', 'occupation_id');
    }
}
