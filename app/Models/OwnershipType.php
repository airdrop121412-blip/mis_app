<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ownership_type — land ownership classification master.
 */
class OwnershipType extends BaseModel
{
    protected $table = 'ownership_type';

    protected $primaryKey = 'ownership_type_id';

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

    public function lands(): HasMany
    {
        return $this->hasMany(Land::class, 'ownership_type_id', 'ownership_type_id');
    }
}
