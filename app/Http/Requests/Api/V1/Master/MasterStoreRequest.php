<?php

namespace App\Http\Requests\Api\V1\Master;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for creating a master record (architecture §5.2, POST).
 *
 * Entity config is resolved from the {entity} route parameter via
 * MasterEntityConfig trait in prepareForValidation().
 */
class MasterStoreRequest extends FormRequest
{
    use MasterEntityConfig;

    public function authorize(): bool
    {
        return true; // Gate handled by permission middleware.
    }

    public function rules(): array
    {
        $config = $this->entityConfig();
        $table = $config['table'];
        $hasCode = $config['has_code'];

        $rules = [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique($table, 'name'),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ];

        if ($hasCode) {
            $rules['code'] = [
                'required',
                'string',
                'max:20',
                Rule::unique($table, 'code'),
            ];
        }

        if (isset($config['parent_fk'])) {
            $parentTable = $config['parent_fk']['table'];
            $parentPk = $config['parent_fk']['key'];
            $rules[$config['parent_fk']['column']] = [
                'required',
                'integer',
                'exists:'.$parentTable.','.$parentPk,
            ];
        }

        return $rules;
    }
}
