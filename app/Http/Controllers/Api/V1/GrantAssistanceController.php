<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GrantAssistance;
use App\Models\GrantAssistanceRate;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Grant & Assistance config (Admin → Grant and Assistance).
 *
 * Routes: /api/v1/grant-assistance
 *   GET  /grant-assistance               — list all items (permission: master.view)
 *   PUT  /grant-assistance/{grant_code}  — update one item (permission: master.manage)
 *
 * These power the EC File calculation for the LDC / DA / SRC / HTG / HCG /
 * CAVHH / AVFHH / MTG / SGfT / GfWL compensation heads. The item set is
 * seeded by migration; writes update the pre-seeded rows.
 *
 * DA (Dislocation Allowance) additionally supports multiple per-scope
 * multiplier records (district + upazila + land category). When present they
 * are sent as the `rates` array and replace the item's existing records.
 */
class GrantAssistanceController extends Controller
{
    /** Allowed calculation types (see migration docblock for semantics). */
    private const CALCULATION_TYPES = ['FIXED', 'PERCENTAGE', 'FIXED_AND_PERCENTAGE', 'MULTIPLIER'];

    /** Allowed percentage bases. */
    private const PERCENTAGE_BASES = ['PAVC', 'RV_STRUCTURE'];

    /**
     * GET /api/v1/grant-assistance
     */
    public function index(Request $request): JsonResponse
    {
        $query = GrantAssistance::query()
            ->with(['rates.district', 'rates.upazila']);

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->input('project_id'));
        }

        $rows = $query
            ->orderBy('grant_assistance_id')
            ->get()
            ->map(fn (GrantAssistance $item) => $this->present($item))
            ->values();

        return ApiResponse::success($rows, 'OK');
    }

    /**
     * PUT /api/v1/grant-assistance/{grant_code}
     */
    public function update(Request $request, string $grantCode): JsonResponse
    {
        $query = GrantAssistance::query()
            ->where('grant_code', $grantCode);

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->input('project_id'));
        }

        $entity = $query->firstOrFail();

        $data = $request->validate($this->rules());

        $rates = $data['rates'] ?? null;
        unset($data['rates']);

        $entity->update($data);

        if (is_array($rates)) {
            $entity->rates()->delete();
            foreach ($rates as $rate) {
                $entity->rates()->create([
                    'district_id' => ($rate['district_id'] ?? null) !== '' ? ($rate['district_id'] ?? null) : null,
                    'upazila_id' => ($rate['upazila_id'] ?? null) !== '' ? ($rate['upazila_id'] ?? null) : null,
                    'land_category' => ($rate['land_category'] ?? null) !== '' ? ($rate['land_category'] ?? null) : null,
                    'project_id' => $request->input('project_id'),
                    'amount' => $rate['amount'] ?? 0,
                    'multiplier' => $rate['multiplier'] ?? 0,
                    'is_active' => $rate['is_active'] ?? true,
                ]);
            }
        }

        $entity->load(['rates.district', 'rates.upazila']);

        return ApiResponse::success($this->present($entity->fresh(['rates.district', 'rates.upazila'])), 'Grant and assistance item updated.');
    }

    /** Flatten a grant item into the API shape. */
    private function present(GrantAssistance $item): array
    {
        return [
            'id' => $item->getKey(),
            'grant_code' => $item->grant_code,
            'grant_name' => $item->grant_name,
            'calculation_type' => $item->calculation_type,
            'amount' => $item->amount,
            'percentage' => $item->percentage,
            'percentage_base' => $item->percentage_base,
            'category' => $item->category,
            'multiplier' => $item->multiplier,
            'is_active' => (bool) $item->is_active,
            'rates' => $item->rates
                ->sortBy('grant_assistance_rate_id')
                ->values()
                ->map(fn (GrantAssistanceRate $rate) => [
                    'id' => $rate->getKey(),
                    'district_id' => $rate->district_id,
                    'district' => $rate->district?->name,
                    'upazila_id' => $rate->upazila_id,
                    'upazila' => $rate->upazila?->name,
                    'land_category' => $rate->land_category,
                    'amount' => $rate->amount,
                    'multiplier' => $rate->multiplier,
                    'is_active' => (bool) $rate->is_active,
                ])
                ->all(),
        ];
    }

    private function rules(): array
    {
        return [
            'grant_name' => 'required|string|max:255',
            'calculation_type' => ['required', 'string', Rule::in(self::CALCULATION_TYPES)],
            'amount' => 'required|numeric|min:0',
            'percentage' => 'required|numeric|min:0|max:100',
            'percentage_base' => ['nullable', 'string', Rule::in(self::PERCENTAGE_BASES)],
            'category' => 'nullable|string|max:255',
            'multiplier' => 'required|numeric|min:0',
            'is_active' => 'sometimes|boolean',
            'rates' => 'sometimes|array',
            'rates.*.district_id' => 'nullable|integer',
            'rates.*.upazila_id' => 'nullable|integer',
            'rates.*.land_category' => 'nullable|string|max:255',
            'rates.*.amount' => 'nullable|numeric|min:0',
            'rates.*.multiplier' => 'required|numeric|min:0',
            'rates.*.is_active' => 'sometimes|boolean',
        ];
    }
}
