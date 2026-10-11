<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * business_ownership_type — OWNER / TENANT (stable code kept in sync with
 * business.ownership_code).
 */
class BusinessOwnershipType extends BaseModel
{
    protected $table = 'business_ownership_type';

    protected $primaryKey = 'business_ownership_type_id';

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
        return $this->hasMany(Business::class, 'business_ownership_type_id', 'business_ownership_type_id');
    }
}
