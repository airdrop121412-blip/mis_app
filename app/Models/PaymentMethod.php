<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * payment_method — CASH / BANK / BKASH / ... master.
 */
class PaymentMethod extends BaseModel
{
    protected $table = 'payment_method';

    protected $primaryKey = 'payment_method_id';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'is_active',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'payment_method_id', 'payment_method_id');
    }
}
