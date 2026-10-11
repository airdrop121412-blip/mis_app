<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * worker — labourer attached to a business or standing alone.
 */
class Worker extends BaseModel
{
    protected $table = 'worker';

    protected $primaryKey = 'worker_id';

    /** @var list<string> */
    protected $fillable = [
        'affected_person_id',
        'ep_id',
        'business_id',
        'name',
        'father_name',
        'mobile',
        'nid_number',
        'mouza',
        'union_name',
        'district_id',
        'upazila_id',
        'total_family_member',
        'duration_of_employment_month',
        'gender_id',
        'gender_other',
        'age',
        'occupation_id',
        'occupation_other',
        'employment_type_id',
        'employment_type_other',
        'monthly_income',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'age' => 'integer',
        'total_family_member' => 'integer',
        'duration_of_employment_month' => 'integer',
        'monthly_income' => 'decimal:2',
    ];

    public function affectedPerson(): BelongsTo
    {
        return $this->belongsTo(AffectedPerson::class, 'affected_person_id', 'affected_person_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'business_id', 'business_id');
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

    public function employmentType(): BelongsTo
    {
        return $this->belongsTo(EmploymentType::class, 'employment_type_id', 'employment_type_id');
    }

    public function assessment(): HasOne
    {
        return $this->hasOne(WorkerAssessment::class, 'worker_id', 'worker_id');
    }
}
