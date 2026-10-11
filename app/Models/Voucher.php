<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * voucher — payment batch per EC.
 * payment_status: PENDING | PARTIAL | PAID | CANCELLED.
 */
class Voucher extends BaseModel
{
    protected $table = 'voucher';

    protected $primaryKey = 'voucher_id';

    /** @var list<string> */
    protected $fillable = [
        'ec_id',
        'voucher_number',
        'voucher_date',
        'approved_amount',
        'payment_status',
        'prepared_by',
        'verified_by',
        'approved_by',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'voucher_date' => 'date',
        'approved_amount' => 'decimal:2',
    ];

    public function ec(): BelongsTo
    {
        return $this->belongsTo(Ec::class, 'ec_id', 'ec_id');
    }

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by', 'user_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by', 'user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by', 'user_id');
    }

    public function voucherCompensations(): HasMany
    {
        return $this->hasMany(VoucherCompensation::class, 'voucher_id', 'voucher_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'voucher_id', 'voucher_id');
    }

    public function additionalPaymentRecords(): HasMany
    {
        return $this->hasMany(AdditionalPaymentRecord::class, 'voucher_id', 'voucher_id');
    }
}
