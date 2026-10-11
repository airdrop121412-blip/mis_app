<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * infrastructure — structural damage record (no replacement/repair cost
 * columns by design; rates come from infrastructure_rate).
 */
class Infrastructure extends BaseModel
{
    protected $table = 'infrastructure';

    protected $primaryKey = 'infrastructure_id';

    /** @var list<string> */
    protected $fillable = [
        'affected_person_id',
        'infrastructure_type_id',
        'infrastructure_type_other',
        'usage_type_id',
        'usage_type_other',
        'structure_nature',
        'structure_material',
        'structure_material_other',
        'unit',
        'length',
        'width',
        'area_sqft',
        'damage_percentage',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'length' => 'decimal:2',
        'width' => 'decimal:2',
        'area_sqft' => 'decimal:2',
        'damage_percentage' => 'decimal:2',
    ];

    public function affectedPerson(): BelongsTo
    {
        return $this->belongsTo(AffectedPerson::class, 'affected_person_id', 'affected_person_id');
    }

    public function infrastructureType(): BelongsTo
    {
        return $this->belongsTo(InfrastructureType::class, 'infrastructure_type_id', 'infrastructure_type_id');
    }

    public function usageType(): BelongsTo
    {
        return $this->belongsTo(UsageType::class, 'usage_type_id', 'usage_type_id');
    }

    public function assessment(): HasOne
    {
        return $this->hasOne(InfrastructureAssessment::class, 'infrastructure_id', 'infrastructure_id');
    }
}
