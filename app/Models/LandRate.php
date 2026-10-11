<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * land_rate — rate master (snapshotted into land_assessment).
 */
class LandRate extends BaseModel
{
    protected $table = 'land_rate';

    protected $primaryKey = 'land_rate_id';

    /** @var list<string> */
    protected $fillable = [
        'land_type_id',
        'land_use_type_id',
        'unit_id',
        'rate_per_unit',
        'effective_date',
        'is_active',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'rate_per_unit' => 'decimal:2',
        'effective_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function landType(): BelongsTo
    {
        return $this->belongsTo(LandType::class, 'land_type_id', 'land_type_id');
    }

    public function landUseType(): BelongsTo
    {
        return $this->belongsTo(LandUseType::class, 'land_use_type_id', 'land_use_type_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(LandAssessment::class, 'rate_id', 'land_rate_id');
    }
}
