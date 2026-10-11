<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * business_type — business classification master.
 */
class BusinessType extends BaseModel
{
    protected $table = 'business_type';

    protected $primaryKey = 'business_type_id';

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

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class, 'business_type_id', 'business_type_id');
    }
}
