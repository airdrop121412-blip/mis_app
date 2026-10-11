<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * gender — person master.
 */
class Gender extends BaseModel
{
    protected $table = 'gender';

    protected $primaryKey = 'gender_id';

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
        return $this->hasMany(AffectedPerson::class, 'gender_id', 'gender_id');
    }

    public function familyMembers(): HasMany
    {
        return $this->hasMany(FamilyMember::class, 'gender_id', 'gender_id');
    }

    public function workers(): HasMany
    {
        return $this->hasMany(Worker::class, 'gender_id', 'gender_id');
    }
}
