<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * family_member — household member linked to an affected person.
 */
class FamilyMember extends BaseModel
{
    protected $table = 'family_member';

    protected $primaryKey = 'family_member_id';

    /** @var list<string> */
    protected $fillable = [
        'affected_person_id',
        'name',
        'relationship_id',
        'relationship_other',
        'gender_id',
        'gender_other',
        'marital_status',
        'age',
        'education',
        'education_other',
        'occupation_id',
        'occupation_other',
        'does_work',
        'annual_income',
        'health_status',
        'disability_type',
        'is_dependent',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'age' => 'integer',
        'does_work' => 'boolean',
        'annual_income' => 'decimal:2',
        'is_dependent' => 'boolean',
    ];

    public function affectedPerson(): BelongsTo
    {
        return $this->belongsTo(AffectedPerson::class, 'affected_person_id', 'affected_person_id');
    }

    public function relationship(): BelongsTo
    {
        return $this->belongsTo(Relationship::class, 'relationship_id', 'relationship_id');
    }

    public function gender(): BelongsTo
    {
        return $this->belongsTo(Gender::class, 'gender_id', 'gender_id');
    }

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class, 'occupation_id', 'occupation_id');
    }
}
