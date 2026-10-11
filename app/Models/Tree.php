<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * tree — a tree lot (type + age category + quantity) belonging to an
 * affected person.
 */
class Tree extends BaseModel
{
    protected $table = 'tree';

    protected $primaryKey = 'tree_id';

    /** @var list<string> */
    protected $fillable = [
        'affected_person_id',
        'name',
        'land_ownership',
        'tree_type_id',
        'tree_type_other',
        'tree_age_category_id',
        'quantity',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'quantity' => 'integer',
    ];

    public function affectedPerson(): BelongsTo
    {
        return $this->belongsTo(AffectedPerson::class, 'affected_person_id', 'affected_person_id');
    }

    public function treeType(): BelongsTo
    {
        return $this->belongsTo(TreeType::class, 'tree_type_id', 'tree_type_id');
    }

    public function treeAgeCategory(): BelongsTo
    {
        return $this->belongsTo(TreeAgeCategory::class, 'tree_age_category_id', 'tree_age_category_id');
    }

    public function assessment(): HasOne
    {
        return $this->hasOne(TreeAssessment::class, 'tree_id', 'tree_id');
    }
}
