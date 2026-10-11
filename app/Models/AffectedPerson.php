<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * affected_person — the single source of truth; one row per EP.
 * status: DRAFT | SUBMITTED | VERIFIED | APPROVED.
 */
class AffectedPerson extends BaseModel
{
    protected $table = 'affected_person';

    protected $primaryKey = 'affected_person_id';

    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'ep_id',
        'hh_id',
        'name',
        'father_name',
        'mother_name',
        'spouse_name',
        'nid_number',
        'mobile',
        'gender_id',
        'gender_other',
        'date_of_birth',
        'age',
        'religion_id',
        'religion_other',
        'occupation_id',
        'occupation_other',
        'district_id',
        'upazila_id',
        'phase',
        'mouza',
        'union_name',
        'category_of_loss',
        'ownership',
        'dc_compensation',
        'cheque_no',
        'cheque_date',
        'dc_tree_compensation',
        'dc_tree_cheque_no',
        'dc_tree_cheque_date',
        'yearly_income',
        'is_disabled',
        'is_landless',
        'is_tribal',
        'has_business_loss',
        'shifting_materials',
        'nature_of_displacement',
        'displacement_status',
        'is_tenant',
        'total_tenant',
        'stamp_duty',
        'no_displacement',
        'land_status_id',
        'rc_type_id',
        'monthly_income',
        'monthly_expense',
        'gps_latitude',
        'gps_longitude',
        'location_accuracy',
        'location_source_id',
        'is_household_head',
        'total_family_member',
        'male_member',
        'female_member',
        'remarks',
        'status',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'date_of_birth' => 'date',
        'age' => 'integer',
        'total_family_member' => 'integer',
        'male_member' => 'integer',
        'female_member' => 'integer',
        'dc_compensation' => 'decimal:2',
        'cheque_date' => 'date',
        'dc_tree_compensation' => 'decimal:2',
        'dc_tree_cheque_date' => 'date',
        'yearly_income' => 'decimal:2',
        'is_disabled' => 'boolean',
        'is_landless' => 'boolean',
        'is_tribal' => 'boolean',
        'has_business_loss' => 'boolean',
        'is_tenant' => 'boolean',
        'total_tenant' => 'integer',
        'stamp_duty' => 'decimal:2',
        'no_displacement' => 'boolean',
        'monthly_income' => 'decimal:2',
        'monthly_expense' => 'decimal:2',
        'gps_latitude' => 'decimal:7',
        'gps_longitude' => 'decimal:7',
        'location_accuracy' => 'decimal:2',
        'is_household_head' => 'boolean',
    ];

    public function canBeEditedBy(User $user): bool
    {
        if ($this->status === 'DRAFT') {
            return true;
        }

        // Only an ADMIN may touch a locked record. SUBMITTED / VERIFIED were
        // always admin-editable; APPROVED is unlocked for the admin Correction
        // workflow, which requires a written reason that is stored in
        // correction_audit before the change is applied.
        if (! $user->roles()->where('code', 'ADMIN')->exists()) {
            return false;
        }

        return in_array($this->status, ['SUBMITTED', 'VERIFIED', 'APPROVED'], true);
    }

    /**
     * True when the acting user is an administrator (admin-correction gate).
     */
    public function isAdministeredBy(User $user): bool
    {
        return $user->roles()->where('code', 'ADMIN')->exists();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function gender(): BelongsTo
    {
        return $this->belongsTo(Gender::class, 'gender_id', 'gender_id');
    }

    public function religion(): BelongsTo
    {
        return $this->belongsTo(Religion::class, 'religion_id', 'religion_id');
    }

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class, 'occupation_id', 'occupation_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_id', 'district_id');
    }

    public function upazila(): BelongsTo
    {
        return $this->belongsTo(Upazila::class, 'upazila_id', 'upazila_id');
    }

    public function landStatus(): BelongsTo
    {
        return $this->belongsTo(LandStatus::class, 'land_status_id', 'land_status_id');
    }

    public function residentialCommercialType(): BelongsTo
    {
        return $this->belongsTo(ResidentialCommercialType::class, 'rc_type_id', 'rc_type_id');
    }

    /** Alias used by controller and resource for brevity. */
    public function rcType(): BelongsTo
    {
        return $this->residentialCommercialType();
    }

    public function locationSource(): BelongsTo
    {
        return $this->belongsTo(LocationSource::class, 'location_source_id', 'location_source_id');
    }

    public function epicad(): HasOne
    {
        return $this->hasOne(Epicad::class, 'affected_person_id', 'affected_person_id');
    }

    public function ec(): HasOne
    {
        return $this->hasOne(Ec::class, 'affected_person_id', 'affected_person_id');
    }

    public function familyMembers(): HasMany
    {
        return $this->hasMany(FamilyMember::class, 'affected_person_id', 'affected_person_id');
    }

    public function lands(): HasMany
    {
        return $this->hasMany(Land::class, 'affected_person_id', 'affected_person_id');
    }

    public function infrastructures(): HasMany
    {
        return $this->hasMany(Infrastructure::class, 'affected_person_id', 'affected_person_id');
    }

    public function trees(): HasMany
    {
        return $this->hasMany(Tree::class, 'affected_person_id', 'affected_person_id');
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class, 'affected_person_id', 'affected_person_id');
    }

    public function businesses(): HasMany
    {
        return $this->hasMany(Business::class, 'affected_person_id', 'affected_person_id');
    }

    public function workers(): HasMany
    {
        return $this->hasMany(Worker::class, 'affected_person_id', 'affected_person_id');
    }

    public function allowances(): HasMany
    {
        return $this->hasMany(Allowance::class, 'affected_person_id', 'affected_person_id');
    }

    public function additionalPaymentRecords(): HasMany
    {
        return $this->hasMany(AdditionalPaymentRecord::class, 'affected_person_id', 'affected_person_id');
    }
}
