<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ec — damage/compensation assessment. Exactly one per epicad/affected person.
 * status: DRAFT | SUBMITTED | VERIFIED | APPROVED | REJECTED.
 */
class Ec extends BaseModel
{
    protected $table = 'ec';

    protected $primaryKey = 'ec_id';

    /** @var list<string> */
    protected $fillable = [
        'affected_person_id',
        'epicad_id',
        'ec_number',
        'assessment_date',
        'total_assessed_loss',
        'total_compensation',
        'status',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'assessment_date' => 'date',
        'total_assessed_loss' => 'decimal:2',
        'total_compensation' => 'decimal:2',
    ];

    public function affectedPerson(): BelongsTo
    {
        return $this->belongsTo(AffectedPerson::class, 'affected_person_id', 'affected_person_id');
    }

    public function epicad(): BelongsTo
    {
        return $this->belongsTo(Epicad::class, 'epicad_id', 'epicad_id');
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(EcLineItem::class, 'ec_id', 'ec_id');
    }

    public function compensations(): HasMany
    {
        return $this->hasMany(Compensation::class, 'ec_id', 'ec_id');
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class, 'ec_id', 'ec_id');
    }
}
