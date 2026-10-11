<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * village — belongs to an upazila; unique per (upazila_id, name).
 */
class Village extends BaseModel
{
    protected $table = 'village';

    protected $primaryKey = 'village_id';

    /** @var list<string> */
    protected $fillable = [
        'upazila_id',
        'name',
        'is_active',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function upazila(): BelongsTo
    {
        return $this->belongsTo(Upazila::class, 'upazila_id', 'upazila_id');
    }
}
