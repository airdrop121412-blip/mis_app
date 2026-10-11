<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * land — land asset belonging to an affected person.
 */
class Land extends BaseModel
{
    protected $table = 'land';

    protected $primaryKey = 'land_id';

    /** @var list<string> */
    protected $fillable = [
        'affected_person_id',
        'land_type_id',
        'land_type_other',
        'land_status_id',
        'ownership_type_id',
        'ownership_type_other',
        'district_id',
        'upazila_id',
        'village_id',
        'mouza',
        'khatian_number',
        'dag_number',
        'la_case_no',
        'dc_pay_amount',
        'dc_cheque_no',
        'dc_pay_date',
        'land_area',
        'land_area_unit_id',
        'land_use_type_id',
        'land_use_type_other',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'land_area' => 'decimal:4',
        'dc_pay_amount' => 'decimal:2',
        'dc_pay_date' => 'date',
    ];

    public function affectedPerson(): BelongsTo
    {
        return $this->belongsTo(AffectedPerson::class, 'affected_person_id', 'affected_person_id');
    }

    public function landType(): BelongsTo
    {
        return $this->belongsTo(LandType::class, 'land_type_id', 'land_type_id');
    }

    public function landStatus(): BelongsTo
    {
        return $this->belongsTo(LandStatus::class, 'land_status_id', 'land_status_id');
    }

    public function ownershipType(): BelongsTo
    {
        return $this->belongsTo(OwnershipType::class, 'ownership_type_id', 'ownership_type_id');
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_id', 'district_id');
    }

    public function upazila(): BelongsTo
    {
        return $this->belongsTo(Upazila::class, 'upazila_id', 'upazila_id');
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class, 'village_id', 'village_id');
    }

    public function landAreaUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'land_area_unit_id', 'unit_id');
    }

    public function landUseType(): BelongsTo
    {
        return $this->belongsTo(LandUseType::class, 'land_use_type_id', 'land_use_type_id');
    }

    public function crops(): HasMany
    {
        return $this->hasMany(Crop::class, 'land_id', 'land_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(LandAssessment::class, 'land_id', 'land_id');
    }
}
