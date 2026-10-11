<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * business_loss — manual loss amount for a business (no rate master).
 * 1:1 with business.
 */
class BusinessLoss extends BaseModel
{
    protected $table = 'business_loss';

    protected $primaryKey = 'business_loss_id';

    /** @var list<string> */
    protected $fillable = [
        'business_id',
        'loss_amount',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'loss_amount' => 'decimal:2',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'business_id', 'business_id');
    }
}
