<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ec_line_item — rollup of one assessment category into an EC
 * (category: LAND|CROP|INFRASTRUCTURE|TREE|BUSINESS|WORKER|OTHER).
 */
class EcLineItem extends BaseModel
{
    protected $table = 'ec_line_item';

    protected $primaryKey = 'ec_line_item_id';

    /** @var list<string> */
    protected $fillable = [
        'ec_id',
        'category',
        'description',
        'assessed_amount',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'assessed_amount' => 'decimal:2',
    ];

    public function ec(): BelongsTo
    {
        return $this->belongsTo(Ec::class, 'ec_id', 'ec_id');
    }
}
