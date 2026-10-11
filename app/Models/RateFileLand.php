<?php

namespace App\Models;

/**
 * rate_file_land — EC Rate File: land rates by mouza + category.
 *
 * Used by the EC File engine to value land compensation against the DC
 * office and PAVC reference rates (per decimal).
 */
class RateFileLand extends BaseModel
{
    protected $table = 'rate_file_land';

    protected $primaryKey = 'rate_file_land_id';

    /** @var list<string> */
    protected $fillable = [
        'mouza',
        'land_category',
        'dc_office_rate',
        'pavc_rate',
        'project_id',
        'is_active',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'dc_office_rate' => 'decimal:2',
        'pavc_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
