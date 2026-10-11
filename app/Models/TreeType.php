<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * tree_type — tree classification master.
 */
class TreeType extends BaseModel
{
    protected $table = 'tree_type';

    protected $primaryKey = 'tree_type_id';

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

    public function trees(): HasMany
    {
        return $this->hasMany(Tree::class, 'tree_type_id', 'tree_type_id');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(TreeRate::class, 'tree_type_id', 'tree_type_id');
    }
}
