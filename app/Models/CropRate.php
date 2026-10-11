<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * crop_rate — rate master (snapshotted into crop_assessment).
 */
class CropRate extends BaseModel
{
    protected $table = 'crop_rate';

    protected $primaryKey = 'crop_rate_id';

    /** @var list<string> */
    protected $fillable = [
        'crop_type_id',
        'crop_season_id',
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

    public function cropType(): BelongsTo
    {
        return $this->belongsTo(CropType::class, 'crop_type_id', 'crop_type_id');
    }

    public function cropSeason(): BelongsTo
    {
        return $this->belongsTo(CropSeason::class, 'crop_season_id', 'crop_season_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id', 'unit_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(CropAssessment::class, 'rate_id', 'crop_rate_id');
    }
}
