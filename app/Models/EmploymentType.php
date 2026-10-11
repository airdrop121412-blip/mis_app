<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * employment_type — worker employment classification master.
 */
class EmploymentType extends BaseModel
{
    protected $table = 'employment_type';

    protected $primaryKey = 'employment_type_id';

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

    public function workers(): HasMany
    {
        return $this->hasMany(Worker::class, 'employment_type_id', 'employment_type_id');
    }
}
