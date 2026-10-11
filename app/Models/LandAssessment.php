<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * land_assessment — damage assessment for a land parcel (rate snapshot).
 */
class LandAssessment extends BaseModel
{
    protected $table = 'land_assessment';

    protected $primaryKey = 'land_assessment_id';

    /** @var list<string> */
    protected $fillable = [
        'land_id',
        'damage_type_id',
        'affected_area',
        'unit_id',
        'rate_id',
        'rate_per_unit',
        'assessed_loss',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'affected_area' => 'decimal:4',
        'rate_per_unit' => 'decimal:2',
        'assessed_loss' => 'decimal:2',
    ];

    public function land(): BelongsTo
    {
        return $this->belongsTo(Land::class, 'land_id', 'land_id');
    }

    public function damageType(): BelongsTo
    {
        return $this->belongsTo(LandDamageType::class, 'damage_type_id', 'land_damage_type_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(LandRate::class, 'rate_id', 'land_rate_id');
    }
}
