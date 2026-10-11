<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * rate_file_tree — EC Rate File: tree rates by geography + tree type + size.
 */
class RateFileTree extends BaseModel
{
    protected $table = 'rate_file_tree';

    protected $primaryKey = 'rate_file_tree_id';

    /** @var list<string> */
    protected $fillable = [
        'district_id',
        'upazila_id',
        'tree_type',
        'size',
        'rate',
        'project_id',
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
