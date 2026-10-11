<?php

namespace App\Http\Requests\Api\V1\Master;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for updating a master record (architecture §5.2, PUT).
 *
 * Unique rules ignore the current record (unique-except-self).
 */
class MasterUpdateRequest extends FormRequest
{
    use MasterEntityConfig;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $config = $this->entityConfig();
        $table = $config['table'];
        $pk = $config['pk'];
        $hasCode = $config['has_code'];
        $recordId = $this->route('id');

        $rules = [
            'name' => [
                'sometimes',
                'string',
                'max:100',
                Rule::unique($table, 'name')->ignore($recordId, $pk),
            ],
            'is_active' => ['sometimes', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:255'],
        ];

        if ($hasCode) {
            $rules['code'] = [
                'sometimes',
                'string',
                'max:20',
                Rule::unique($table, 'code')->ignore($recordId, $pk),
            ];
        }

        if (isset($config['parent_fk'])) {
            $parentTable = $config['parent_fk']['table'];
            $parentPk = $config['parent_fk']['key'];
            $rules[$config['parent_fk']['column']] = [
                'sometimes',
                'integer',
                'exists:'.$parentTable.','.$parentPk,
            ];
        }

        return $rules;
    }
}
