<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * infrastructure_assessment — damage assessment for infrastructure (rate
 * snapshot).
 */
class InfrastructureAssessment extends BaseModel
{
    protected $table = 'infrastructure_assessment';

    protected $primaryKey = 'infrastructure_assessment_id';

    /** @var list<string> */
    protected $fillable = [
        'infrastructure_id',
        'rate_id',
        'area_sqft',
        'rate_per_sqft',
        'damage_percentage',
        'assessed_loss',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'area_sqft' => 'decimal:2',
        'rate_per_sqft' => 'decimal:2',
        'damage_percentage' => 'decimal:2',
        'assessed_loss' => 'decimal:2',
    ];

    public function infrastructure(): BelongsTo
    {
        return $this->belongsTo(Infrastructure::class, 'infrastructure_id', 'infrastructure_id');
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(InfrastructureRate::class, 'rate_id', 'infrastructure_rate_id');
    }
}
