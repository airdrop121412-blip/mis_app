<?php

namespace App\Http\Requests\Api\V1\Ep;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for creating / updating an EPICAD design.
 */
class EpicadDesignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => ['sometimes', 'required', 'integer'],
            'name' => ['nullable', 'string', 'max:150'],
            'orientation' => ['nullable', 'in:portrait,landscape'],
            'page_size' => ['nullable', 'string', 'max:20'],
            'layout_json' => ['nullable', 'string'],
            'background_url' => ['nullable', 'string'],
            'css' => ['nullable', 'string'],
            'html_template' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
