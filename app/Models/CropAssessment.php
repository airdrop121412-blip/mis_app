<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * crop_assessment — damage assessment for a crop (rate snapshot).
 */
class CropAssessment extends BaseModel
{
    protected $table = 'crop_assessment';

    protected $primaryKey = 'crop_assessment_id';

    /** @var list<string> */
    protected $fillable = [
        'crop_id',
        'rate_id',
        'cultivated_area',
        'rate_per_unit',
        'damage_percentage',
        'assessed_loss',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'cultivated_area' => 'decimal:4',
        'rate_per_unit' => 'decimal:2',
        'damage_percentage' => 'decimal:2',
        'assessed_loss' => 'decimal:2',
    ];

    public function crop(): BelongsTo
    {
        return $this->belongsTo(Crop::class, 'crop_id', 'crop_id');
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(CropRate::class, 'rate_id', 'crop_rate_id');
    }
}
