<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * tree_age_category — PLANT / SMALL / MEDIUM / BIG (ordered by sort_order).
 */
class TreeAgeCategory extends BaseModel
{
    protected $table = 'tree_age_category';

    protected $primaryKey = 'tree_age_category_id';

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

    public function trees(): HasMany
    {
        return $this->hasMany(Tree::class, 'tree_age_category_id', 'tree_age_category_id');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(TreeRate::class, 'tree_age_category_id', 'tree_age_category_id');
    }
}
