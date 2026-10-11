<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * tenant — renter linked to an affected person. Unique
 * (affected_person_id, tenant_id) is the composite-FK target for business
 * ownership integrity.
 */
class Tenant extends BaseModel
{
    protected $table = 'tenant';

    protected $primaryKey = 'tenant_id';

    /** @var list<string> */
    protected $fillable = [
        'affected_person_id',
        'ep_id',
        'hh_id',
        'tenant_type_id',
        'name',
        'father_name',
        'nid_number',
        'mobile',
        'mouza',
        'union_name',
        'district_id',
        'upazila_id',
        'age',
        'gender_id',
        'occupation_id',
        'occupation_other',
        'annual_income',
        'infrastructure_id',
        'address',
        'agreement_number',
        'monthly_rent',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'monthly_rent' => 'decimal:2',
        'annual_income' => 'decimal:2',
        'district_id' => 'integer',
        'upazila_id' => 'integer',
        'age' => 'integer',
        'gender_id' => 'integer',
        'occupation_id' => 'integer',
        'infrastructure_id' => 'integer',
    ];

    public function affectedPerson(): BelongsTo
    {
        return $this->belongsTo(AffectedPerson::class, 'affected_person_id', 'affected_person_id');
    }

    public function tenantType(): BelongsTo
    {
        return $this->belongsTo(TenantType::class, 'tenant_type_id', 'tenant_type_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_id', 'district_id');
    }

    public function upazila(): BelongsTo
    {
        return $this->belongsTo(Upazila::class, 'upazila_id', 'upazila_id');
    }

    public function gender(): BelongsTo
    {
        return $this->belongsTo(Gender::class, 'gender_id', 'gender_id');
    }

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class, 'occupation_id', 'occupation_id');
    }

    public function infrastructure(): BelongsTo
    {
        return $this->belongsTo(Infrastructure::class, 'infrastructure_id', 'infrastructure_id');
    }

    public function infrastructures(): BelongsToMany
    {
        return $this->belongsToMany(
            Infrastructure::class,
            'tenant_infrastructure',
            'tenant_id',
            'infrastructure_id',
        );
    }

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class, 'tenant_id', 'tenant_id');
    }
}
