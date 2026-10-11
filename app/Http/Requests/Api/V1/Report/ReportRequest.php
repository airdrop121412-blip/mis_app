<?php

namespace App\Http\Requests\Api\V1\Report;

use App\Support\CompensationCategories;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared validation for report endpoints (§18).
 *
 * Validates common filter parameters and the export format.
 * Applied to both GET (screen) and GET (export) report routes.
 */
class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => 'nullable|integer|min:1',
            'district_id' => 'nullable|integer|min:1',
            'upazila_id' => 'nullable|integer|min:1',
            'status' => ['nullable', 'string', 'max:30'],
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
            'category' => ['nullable', 'string', Rule::in(CompensationCategories::ACCEPTED)],
            'rule_code' => 'nullable|string|max:30',
            'payment_method_id' => 'nullable|integer|min:1',
            'print_status' => ['nullable', 'string', Rule::in(['NOT_PRINTED', 'PRINTED', 'REPRINTED'])],
            'per_page' => 'nullable|integer|min:1|max:100',
            'format' => ['nullable', 'string', Rule::in(['json', 'csv', 'xlsx', 'pdf'])],
            // Report-specific filters.
            'land_type_id' => 'nullable|integer|min:1',
            'crop_type_id' => 'nullable|integer|min:1',
            'season_id' => 'nullable|integer|min:1',
            'payment_status' => ['nullable', 'string', Rule::in(['PENDING', 'PARTIAL', 'PAID'])],
            'outstanding_only' => ['nullable', 'string', Rule::in(['0', '1'])],
        ];
    }

    /**
     * Get the normalised filter array for dispatching to a report query.
     */
    public function toFilters(): array
    {
        return collect($this->validated())
            ->only([
                'project_id', 'district_id', 'upazila_id', 'status',
                'from_date', 'to_date', 'category', 'rule_code',
                'payment_method_id', 'print_status', 'per_page',
                'land_type_id', 'crop_type_id', 'season_id',
                'payment_status', 'outstanding_only',
            ])
            ->filter()
            ->toArray();
    }
}
