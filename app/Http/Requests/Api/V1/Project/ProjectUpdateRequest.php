<?php

namespace App\Http\Requests\Api\V1\Project;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for updating a project (architecture §5.3, PUT).
 */
class ProjectUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $recordId = $this->route('project');

        return [
            'project_code' => [
                'sometimes',
                'string',
                'max:30',
                Rule::unique('project', 'project_code')->ignore($recordId, 'project_id'),
            ],
            'name' => [
                'sometimes',
                'string',
                'max:200',
                Rule::unique('project', 'name')->ignore($recordId, 'project_id'),
            ],
            'phase' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ];
    }
}
