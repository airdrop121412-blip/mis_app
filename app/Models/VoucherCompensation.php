<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * voucher_compensation — which compensation lines are in a voucher.
 * UNIQUE(compensation_id) is the DB-level duplicate-payment guard.
 */
class VoucherCompensation extends BaseModel
{
    protected $table = 'voucher_compensation';

    protected $primaryKey = 'voucher_compensation_id';

    /** @var list<string> */
    protected $fillable = [
        'voucher_id',
        'compensation_id',
        'amount',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'voucher_id', 'voucher_id');
    }

    public function compensation(): BelongsTo
    {
        return $this->belongsTo(Compensation::class, 'compensation_id', 'compensation_id');
    }
}
