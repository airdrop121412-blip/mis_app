<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadata for a child row entered after the EP's original payment.
 *
 * Original rows have no metadata row and are therefore permanently locked.
 */
class AdditionalPaymentRecord extends BaseModel
{
    protected $table = 'additional_payment_record';

    protected $primaryKey = 'additional_payment_record_id';

    protected $fillable = [
        'affected_person_id',
        'record_type',
        'record_id',
        'voucher_id',
        'payment_cycle',
        'locked_at',
    ];

    protected $casts = [
        'payment_cycle' => 'integer',
        'locked_at' => 'datetime',
    ];

    public function affectedPerson(): BelongsTo
    {
        return $this->belongsTo(AffectedPerson::class, 'affected_person_id', 'affected_person_id');
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class, 'voucher_id', 'voucher_id');
    }
}
