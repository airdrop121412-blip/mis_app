<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * compensation — approved amount per EC per category.
 * status: DRAFT | APPROVED | PAID | CANCELLED.
 */
class Compensation extends BaseModel
{
    protected $table = 'compensation';

    protected $primaryKey = 'compensation_id';

    /** @var list<string> */
    protected $fillable = [
        'ec_id',
        'category',
        'assessed_amount',
        'approved_amount',
        'status',
        'payment_cycle',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'assessed_amount' => 'decimal:2',
        'approved_amount' => 'decimal:2',
        'payment_cycle' => 'integer',
    ];

    public function ec(): BelongsTo
    {
        return $this->belongsTo(Ec::class, 'ec_id', 'ec_id');
    }

    public function voucherCompensations(): HasMany
    {
        return $this->hasMany(VoucherCompensation::class, 'compensation_id', 'compensation_id');
    }
}
