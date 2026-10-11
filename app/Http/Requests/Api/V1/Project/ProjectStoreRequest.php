<?php

namespace App\Http\Requests\Api\V1\Project;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for creating a project (architecture §5.3, POST).
 */
class ProjectStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_code' => [
                'required',
                'string',
                'max:30',
                Rule::unique('project', 'project_code'),
            ],
            'name' => [
                'required',
                'string',
                'max:200',
                Rule::unique('project', 'name'),
            ],
            'phase' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date', 'before_or_equal:end_date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'is_active' => ['sometimes', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ];
    }
}
