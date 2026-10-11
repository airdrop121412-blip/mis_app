<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * crop — a crop planted on a parcel of land.
 */
class Crop extends BaseModel
{
    protected $table = 'crop';

    protected $primaryKey = 'crop_id';

    /** @var list<string> */
    protected $fillable = [
        'land_id',
        'crop_type_id',
        'crop_type_other',
        'crop_season_id',
        'cultivated_area',
        'area_unit_id',
        'damage_percentage',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'cultivated_area' => 'decimal:4',
        'damage_percentage' => 'decimal:2',
    ];

    public function land(): BelongsTo
    {
        return $this->belongsTo(Land::class, 'land_id', 'land_id');
    }

    public function cropType(): BelongsTo
    {
        return $this->belongsTo(CropType::class, 'crop_type_id', 'crop_type_id');
    }

    public function cropSeason(): BelongsTo
    {
        return $this->belongsTo(CropSeason::class, 'crop_season_id', 'crop_season_id');
    }

    public function areaUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'area_unit_id', 'unit_id');
    }

    public function assessment(): HasOne
    {
        return $this->hasOne(CropAssessment::class, 'crop_id', 'crop_id');
    }
}
