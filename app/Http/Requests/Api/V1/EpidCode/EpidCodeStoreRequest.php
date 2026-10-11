<?php

namespace App\Http\Requests\Api\V1\EpidCode;

use App\Models\EpidCode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for creating an EP ID code entry (Admin → EP ID Codes).
 *
 * Name must be unique within the row's category.
 * Code must be unique within the row's category AND district_id
 * (for upazila, the same code can be reused across different districts).
 */
class EpidCodeStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $category = $this->input('category');

        return [
            'project_id' => ['required', 'integer', 'exists:project,project_id'],
            'category' => ['required', 'string', Rule::in(array_keys(EpidCode::CATEGORIES))],
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('epid_code', 'name')
                    ->where(fn ($q) => $q->where('category', $category)->where('project_id', $this->input('project_id'))),
            ],
            'code' => [
                'required',
                'string',
                'max:'.(EpidCode::CODE_MAX_LENGTH[$category] ?? 20),
                'regex:/^[A-Za-z0-9-]+$/',
                Rule::unique('epid_code', 'code')
                    ->where(fn ($q) => $q->where('category', $category)->where('project_id', $this->input('project_id')))
                    ->where(function ($q) {
                        $q->whereNull('district_id')
                            ->orWhere('district_id', $this->input('district_id'));
                    }),
            ],
            'district_id' => [
                $category === EpidCode::CATEGORY_UPAZILA ? 'required' : 'nullable',
                'integer',
                'exists:district,district_id',
            ],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ];
    }
}
