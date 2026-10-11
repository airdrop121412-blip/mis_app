<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * payment — actual disbursement against a voucher.
 * Sum(payments) <= voucher.approved_amount is enforced at the service layer
 * (cannot be a MySQL CHECK).
 */
class Payment extends BaseModel
{
    protected $table = 'payment';

    protected $primaryKey = 'payment_id';

    /** @var list<string> */
    protected $fillable = [
        'voucher_id',
        'payment_method_id',
        'amount',
        'payment_reference',
        'payment_date',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'date',
    ];

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'voucher_id', 'voucher_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id', 'payment_method_id');
    }
}
