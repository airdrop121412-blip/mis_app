<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * relationship — family-membership master (e.g. SELF, SPOUSE, CHILD...).
 */
class Relationship extends BaseModel
{
    protected $table = 'relationship';

    protected $primaryKey = 'relationship_id';

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

    public function familyMembers(): HasMany
    {
        return $this->hasMany(FamilyMember::class, 'relationship_id', 'relationship_id');
    }
}
