<?php

namespace App\Http\Requests\Api\V1\Ep;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for creating an affected person / EP (architecture §5.4, OP-4).
 *
 * EP ID is generated server-side by EpIdService; the client does not
 * supply it. status always starts as 'DRAFT'.
 *
 * The rule set is exposed statically via self::epRules() so the Excel bulk
 * importer validates every row with exactly the same rules.
 */
class AffectedPersonStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return self::epRules((int) $this->input('project_id'));
    }

    /**
     * Validation rules for an EP row, scoped to a project.
     *
     * @return array<string, mixed>
     */
    public static function epRules(int $projectId): array
    {
        return [
            'project_id' => ['required', 'integer', 'exists:project,project_id'],
            'hh_id' => ['nullable', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:150'],
            'father_name' => ['nullable', 'string', 'max:150'],
            'mother_name' => ['nullable', 'string', 'max:150'],
            'spouse_name' => ['nullable', 'string', 'max:150'],
            'nid_number' => [
                'required',
                'string',
                'max:17',
                'regex:/^[0-9]{10}$|^[0-9]{17}$/',
                Rule::unique('affected_person', 'nid_number')
                    ->where(fn ($q) => $q->where('project_id', $projectId)),
            ],
            'mobile' => ['required', 'string', 'digits_between:10,15'],
            'gender_id' => ['required', 'integer', 'exists:gender,gender_id'],
            'gender_other' => ['nullable', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'age' => ['nullable', 'integer', 'min:0', 'max:150'],
            'religion_id' => ['nullable', 'integer', 'exists:religion,religion_id'],
            'religion_other' => ['nullable', 'string', 'max:255'],
            'occupation_id' => ['nullable', 'integer', 'exists:occupation,occupation_id'],
            'occupation_other' => ['nullable', 'string', 'max:255'],
            'district_id' => ['required', 'integer', 'exists:district,district_id'],
            'upazila_id' => ['required', 'integer', 'exists:upazila,upazila_id'],
            'phase' => ['nullable', 'string', 'max:50'],
            'mouza' => ['nullable', 'string', 'max:150'],
            'union_name' => ['nullable', 'string', 'max:150'],
            'category_of_loss' => ['nullable', 'string', 'max:100'],
            'ownership' => ['nullable', 'string', 'max:100'],
            'dc_compensation' => ['nullable', 'numeric', 'min:0'],
            'cheque_no' => ['nullable', 'string', 'max:50'],
            'cheque_date' => ['nullable', 'date'],
            'dc_tree_compensation' => ['nullable', 'numeric', 'min:0'],
            'dc_tree_cheque_no' => ['nullable', 'string', 'max:50'],
            'dc_tree_cheque_date' => ['nullable', 'date'],
            'yearly_income' => ['nullable', 'numeric', 'min:0'],
            'is_disabled' => ['sometimes', 'boolean'],
            'is_landless' => ['sometimes', 'boolean'],
            'is_tribal' => ['sometimes', 'boolean'],
            'has_business_loss' => ['sometimes', 'boolean'],
            'shifting_materials' => ['nullable', 'string', 'max:255'],
            'nature_of_displacement' => ['nullable', 'string', 'max:100'],
            'displacement_status' => ['nullable', 'string', 'max:100'],
            'is_tenant' => ['sometimes', 'boolean'],
            'total_tenant' => ['nullable', 'integer', 'min:0'],
            'stamp_duty' => ['nullable', 'numeric', 'min:0'],
            'no_displacement' => ['sometimes', 'boolean'],
            'land_status_id' => ['required_without:ownership', 'integer', 'exists:land_status,land_status_id'],
            'rc_type_id' => ['required', 'integer', 'exists:residential_commercial_type,rc_type_id'],
            'monthly_income' => ['nullable', 'numeric', 'min:0'],
            'monthly_expense' => ['nullable', 'numeric', 'min:0'],
            'gps_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'gps_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'location_accuracy' => ['nullable', 'numeric', 'min:0'],
            'location_source_id' => ['nullable', 'integer', 'exists:location_source,location_source_id'],
            'is_household_head' => ['sometimes', 'boolean'],
            'total_family_member' => ['nullable', 'integer', 'min:0'],
            'male_member' => ['nullable', 'integer', 'min:0'],
            'female_member' => ['nullable', 'integer', 'min:0'],
            'remarks' => ['nullable', 'string'],
        ];
    }
}
