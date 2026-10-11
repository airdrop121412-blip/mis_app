<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * business — a business operated by an affected person, either owned
 * (tenant_id NULL) or leased from one of their tenants (composite FK
 * (affected_person_id, tenant_id) -> tenant).
 */
class Business extends BaseModel
{
    protected $table = 'business';

    protected $primaryKey = 'business_id';

    /** @var list<string> */
    protected $fillable = [
        'affected_person_id',
        'business_ownership_type_id',
        'ownership_code',
        'tenant_id',
        'business_type_id',
        'business_type_other',
        'business_category_id',
        'business_name',
        'present_address',
        'capital_amount',
        'annual_income',
        'has_wage_labour',
        'number_of_wage_labour',
        'has_income_tax',
        'income_tax_id',
        'latitude',
        'longitude',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'capital_amount' => 'decimal:2',
        'annual_income' => 'decimal:2',
        'has_wage_labour' => 'boolean',
        'number_of_wage_labour' => 'integer',
        'has_income_tax' => 'boolean',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    public function affectedPerson(): BelongsTo
    {
        return $this->belongsTo(AffectedPerson::class, 'affected_person_id', 'affected_person_id');
    }

    public function businessOwnershipType(): BelongsTo
    {
        return $this->belongsTo(BusinessOwnershipType::class, 'business_ownership_type_id', 'business_ownership_type_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'tenant_id');
    }

    public function businessType(): BelongsTo
    {
        return $this->belongsTo(BusinessType::class, 'business_type_id', 'business_type_id');
    }

    public function businessCategory(): BelongsTo
    {
        return $this->belongsTo(BusinessCategory::class, 'business_category_id', 'business_category_id');
    }

    public function workers(): HasMany
    {
        return $this->hasMany(Worker::class, 'business_id', 'business_id');
    }

    public function loss(): HasOne
    {
        return $this->hasOne(BusinessLoss::class, 'business_id', 'business_id');
    }
}
