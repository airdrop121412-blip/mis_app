<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * grant_assistance — Admin → Grant and Assistance config.
 *
 * One row per resettlement grant / assistance item. The EC File engine reads
 * these when computing the Compensation column for the LDC, DA, SRC, HTG,
 * HCG, CAVHH, AVFHH, MTG, SGfT and GfWL heads.
 */
class GrantAssistance extends BaseModel
{
    protected $table = 'grant_assistance';

    protected $primaryKey = 'grant_assistance_id';

    /** @var list<string> */
    protected $fillable = [
        'grant_code',
        'grant_name',
        'calculation_type',
        'amount',
        'percentage',
        'percentage_base',
        'category',
        'multiplier',
        'project_id',
        'is_active',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'amount' => 'decimal:2',
        'percentage' => 'decimal:2',
        'multiplier' => 'decimal:2',
        'project_id' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    /** Per-scope multiplier records (DA district/upazila/land-category). */
    public function rates(): HasMany
    {
        return $this->hasMany(GrantAssistanceRate::class, 'grant_assistance_id', 'grant_assistance_id');
    }
}
