<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * grant_assistance_rate — per-scope records for a Grant & Assistance item.
 *   DA uses district/upazila/land_category × multiplier.
 *   CfB uses district × amount (one-time BDT amount per district);
 *       district null = any district. A CfB record's multiplier stays 0.
 */
class GrantAssistanceRate extends BaseModel
{
    protected $table = 'grant_assistance_rate';

    protected $primaryKey = 'grant_assistance_rate_id';

    /** @var list<string> */
    protected $fillable = [
        'grant_assistance_id',
        'district_id',
        'upazila_id',
        'land_category',
        'amount',
        'multiplier',
        'project_id',
        'is_active',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'district_id' => 'integer',
        'upazila_id' => 'integer',
        'project_id' => 'integer',
        'amount' => 'decimal:2',
        'multiplier' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function grant(): BelongsTo
    {
        return $this->belongsTo(GrantAssistance::class, 'grant_assistance_id', 'grant_assistance_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_id', 'district_id');
    }

    public function upazila(): BelongsTo
    {
        return $this->belongsTo(Upazila::class, 'upazila_id', 'upazila_id');
    }
}
