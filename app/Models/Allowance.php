<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * allowance — vulnerability-based grant for an affected person.
 * status: DRAFT | APPROVED | PAID | REJECTED.
 */
class Allowance extends BaseModel
{
    protected $table = 'allowance';

    protected $primaryKey = 'allowance_id';

    /** @var list<string> */
    protected $fillable = [
        'affected_person_id',
        'vulnerability_rule_id',
        'is_eligible',
        'computed_annual_income',
        'grant_amount',
        'status',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_eligible' => 'boolean',
        'computed_annual_income' => 'decimal:2',
        'grant_amount' => 'decimal:2',
    ];

    public function affectedPerson(): BelongsTo
    {
        return $this->belongsTo(AffectedPerson::class, 'affected_person_id', 'affected_person_id');
    }

    public function vulnerabilityRule(): BelongsTo
    {
        return $this->belongsTo(VulnerabilityRule::class, 'vulnerability_rule_id', 'vulnerability_rule_id');
    }
}
