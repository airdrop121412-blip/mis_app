<?php

namespace App\Http\Resources\Api\V1\Ep;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for affected_person (architecture §5.4).
 * Includes master name lookups via eager-loaded relationships.
 */
class AffectedPersonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'affected_person_id' => $this->affected_person_id,
            'project_id' => $this->project_id,
            'ep_id' => $this->ep_id,
            'hh_id' => $this->hh_id,
            'name' => $this->name,
            'father_name' => $this->father_name,
            'mother_name' => $this->mother_name,
            'spouse_name' => $this->spouse_name,
            'nid_number' => $this->nid_number,
            'mobile' => $this->mobile,
            'gender_id' => $this->gender_id,
            'gender' => $this->whenLoaded('gender', fn () => $this->gender->name),
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),
            'age' => $this->age,
            'religion_id' => $this->religion_id,
            'religion' => $this->whenLoaded('religion', fn () => $this->religion->name),
            'occupation_id' => $this->occupation_id,
            'occupation' => $this->whenLoaded('occupation', fn () => $this->occupation->name),
            'district_id' => $this->district_id,
            'district' => $this->whenLoaded('district', fn () => $this->district->name),
            'upazila_id' => $this->upazila_id,
            'upazila' => $this->whenLoaded('upazila', fn () => $this->upazila->name),
            'phase' => $this->phase,
            'mouza' => $this->mouza,
            'union_name' => $this->union_name,
            'category_of_loss' => $this->category_of_loss,
            'ownership' => $this->ownership,
            'dc_compensation' => $this->dc_compensation,
            'cheque_no' => $this->cheque_no,
            'cheque_date' => $this->cheque_date?->format('Y-m-d'),
            'dc_tree_compensation' => $this->dc_tree_compensation,
            'dc_tree_cheque_no' => $this->dc_tree_cheque_no,
            'dc_tree_cheque_date' => $this->dc_tree_cheque_date?->format('Y-m-d'),
            'yearly_income' => $this->yearly_income,
            'is_disabled' => $this->is_disabled,
            'is_landless' => $this->is_landless,
            'is_tribal' => $this->is_tribal,
            'has_business_loss' => $this->has_business_loss,
            'shifting_materials' => $this->shifting_materials,
            'nature_of_displacement' => $this->nature_of_displacement,
            'displacement_status' => $this->displacement_status,
            'is_tenant' => $this->is_tenant,
            'total_tenant' => $this->total_tenant,
            'stamp_duty' => $this->stamp_duty,
            'no_displacement' => $this->no_displacement,
            'land_status_id' => $this->land_status_id,
            'land_status' => $this->whenLoaded('landStatus', fn () => $this->landStatus->name),
            'rc_type_id' => $this->rc_type_id,
            'rc_type' => $this->whenLoaded('rcType', fn () => $this->rcType->name),
            'monthly_income' => $this->monthly_income,
            'monthly_expense' => $this->monthly_expense,
            'gps_latitude' => $this->gps_latitude,
            'gps_longitude' => $this->gps_longitude,
            'location_accuracy' => $this->location_accuracy,
            'location_source_id' => $this->location_source_id,
            'is_household_head' => $this->is_household_head,
            'total_family_member' => $this->total_family_member,
            'male_member' => $this->male_member,
            'female_member' => $this->female_member,
            'remarks' => $this->remarks,
            'status' => $this->status,
            'ec_status' => $this->whenLoaded('ec', fn () => $this->ec?->status ?? null),
            'ec_number' => $this->whenLoaded('ec', fn () => $this->ec?->ec_number ?? null),
            'card_kind' => $this->card_kind ?? 'ep',
            'card_subject_id' => $this->card_subject_id ?? null,
            'card_subject_ep_id' => $this->card_subject_ep_id ?? null,
            'card_subject_name' => $this->card_subject_name ?? null,
        ];
    }
}
