<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * infrastructure_rate — rate master (snapshotted into infrastructure_assessment).
 */
class InfrastructureRate extends BaseModel
{
    protected $table = 'infrastructure_rate';

    protected $primaryKey = 'infrastructure_rate_id';

    /** @var list<string> */
    protected $fillable = [
        'infrastructure_type_id',
        'usage_type_id',
        'rate_per_sqft',
        'effective_date',
        'is_active',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'rate_per_sqft' => 'decimal:2',
        'effective_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function infrastructureType(): BelongsTo
    {
        return $this->belongsTo(InfrastructureType::class, 'infrastructure_type_id', 'infrastructure_type_id');
    }

    public function usageType(): BelongsTo
    {
        return $this->belongsTo(UsageType::class, 'usage_type_id', 'usage_type_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(InfrastructureAssessment::class, 'rate_id', 'infrastructure_rate_id');
    }
}
