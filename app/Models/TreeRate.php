<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * tree_rate — rate master (snapshotted into tree_assessment).
 */
class TreeRate extends BaseModel
{
    protected $table = 'tree_rate';

    protected $primaryKey = 'tree_rate_id';

    /** @var list<string> */
    protected $fillable = [
        'tree_type_id',
        'tree_age_category_id',
        'rate_per_tree',
        'effective_date',
        'is_active',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'rate_per_tree' => 'decimal:2',
        'effective_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function treeType(): BelongsTo
    {
        return $this->belongsTo(TreeType::class, 'tree_type_id', 'tree_type_id');
    }

    public function treeAgeCategory(): BelongsTo
    {
        return $this->belongsTo(TreeAgeCategory::class, 'tree_age_category_id', 'tree_age_category_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(TreeAssessment::class, 'rate_id', 'tree_rate_id');
    }
}
