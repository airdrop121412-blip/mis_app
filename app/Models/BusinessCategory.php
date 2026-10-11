<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * business_category — SMALL / MEDIUM / BIG (ordered by sort_order).
 */
class BusinessCategory extends BaseModel
{
    protected $table = 'business_category';

    protected $primaryKey = 'business_category_id';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'sort_order',
        'is_active',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class, 'business_category_id', 'business_category_id');
    }
}
