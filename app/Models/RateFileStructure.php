<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * rate_file_structure — EC Rate File: structure rates by geography +
 * material + usage type + nature + unit.
 */
class RateFileStructure extends BaseModel
{
    protected $table = 'rate_file_structure';

    protected $primaryKey = 'rate_file_structure_id';

    /** @var list<string> */
    protected $fillable = [
        'district_id',
        'upazila_id',
        'project_id',
        'material',
        'usage_type',
        'nature',
        'unit',
        'rate',
        'is_active',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'district_id' => 'integer',
        'upazila_id' => 'integer',
        'project_id' => 'integer',
        'rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
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
