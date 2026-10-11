<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * tree_assessment — damage assessment for a tree lot (rate snapshot).
 */
class TreeAssessment extends BaseModel
{
    protected $table = 'tree_assessment';

    protected $primaryKey = 'tree_assessment_id';

    /** @var list<string> */
    protected $fillable = [
        'tree_id',
        'rate_id',
        'quantity',
        'rate_per_tree',
        'assessed_loss',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'quantity' => 'integer',
        'rate_per_tree' => 'decimal:2',
        'assessed_loss' => 'decimal:2',
    ];

    public function tree(): BelongsTo
    {
        return $this->belongsTo(Tree::class, 'tree_id', 'tree_id');
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(TreeRate::class, 'rate_id', 'tree_rate_id');
    }
}
