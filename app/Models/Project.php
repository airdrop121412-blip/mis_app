<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * project — a compensation programme (project_code unique).
 */
class Project extends BaseModel
{
    protected $table = 'project';

    protected $primaryKey = 'project_id';

    /** @var list<string> */
    protected $fillable = [
        'project_code',
        'name',
        'phase',
        'description',
        'start_date',
        'end_date',
        'is_active',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function affectedPersons(): HasMany
    {
        return $this->hasMany(AffectedPerson::class, 'project_id', 'project_id');
    }

    public function epSequences(): HasMany
    {
        return $this->hasMany(EpSequence::class, 'project_id', 'project_id');
    }
}
