<?php

namespace App\Http\Requests\Api\V1\EpidCode;

use App\Models\EpidCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for updating an EP ID code entry (Admin → EP ID Codes).
 */
class EpidCodeUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $category = $this->input('category');
        $recordId = $this->route('epid_code');

        if (! $category && $recordId instanceof EpidCode) {
            $category = $recordId->category;
        }

        // Scope uniqueness to the record's project (and district for upazila)
        // even on partial updates that don't resend those fields.
        $projectId = $this->input('project_id')
            ?? ($recordId instanceof EpidCode ? $recordId->project_id : null);
        $districtId = $this->input('district_id')
            ?? ($recordId instanceof EpidCode ? $recordId->district_id : null);

        return [
            'category' => ['sometimes', 'string', Rule::in(array_keys(EpidCode::CATEGORIES))],
            'name' => [
                'string',
                'max:150',
                Rule::unique('epid_code', 'name')
                    ->where(fn ($q) => $q->where('category', $category)->where('project_id', $projectId))
                    ->ignore($recordId, 'epid_code_id'),
            ],
            'code' => [
                'string',
                'max:'.(EpidCode::CODE_MAX_LENGTH[$category] ?? 20),
                'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('epid_code', 'code')
                    ->where(fn ($q) => $q->where('category', $category)->where('project_id', $projectId))
                    ->where(function ($q) use ($districtId) {
                        // Upazila codes are unique per district: the same code
                        // may be reused in a different district. Other
                        // categories have district_id NULL and stay unique
                        // per (project, category).
                        $q->whereNull('district_id')
                            ->orWhere('district_id', $districtId);
                    })
                    ->ignore($recordId, 'epid_code_id'),
            ],
            'district_id' => [
                'nullable',
                'integer',
                'exists:district,district_id',
                // Only enforce when the client actually sends district_id: a
                // partial update (e.g. activating) must not fail. Null is still
                // rejected for upazila so the link can never be cleared silently.
                Rule::requiredIf(fn () => $category === EpidCode::CATEGORY_UPAZILA && $this->exists('district_id')),
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ];
    }
}
