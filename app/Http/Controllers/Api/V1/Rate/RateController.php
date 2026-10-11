<?php

namespace App\Http\Controllers\Api\V1\Rate;

use App\Http\Controllers\Controller;
use App\Models\CropRate;
use App\Models\InfrastructureRate;
use App\Models\LandRate;
use App\Models\TreeRate;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rate lookup — exposes the active rate masters so the Entry/Assessment
 * frontend can populate assessment rate dropdowns.
 *
 * The rate tables are NOT part of the generic master whitelist (they carry
 * composite keys + a rate amount), so they get a dedicated read-only endpoint.
 *
 * Routes: /api/v1/rates/{type}  (type ∈ land | tree | infrastructure | crop)
 */
class RateController extends Controller
{
    /**
     * GET /api/v1/rates/{type}
     *
     * Returns active rates with resolved labels. Optional filters per type:
     *   land:            ?land_type_id= & ?land_use_type_id= & ?unit_id=
     *   tree:            ?tree_type_id= & ?tree_age_category_id=
     *   infrastructure:  ?infrastructure_type_id= & ?usage_type_id=
     *   crop:            ?crop_type_id= & ?crop_season_id= & ?unit_id=
     */
    public function index(Request $request, string $type): JsonResponse
    {
        return match ($type) {
            'land' => $this->landRates($request),
            'tree' => $this->treeRates($request),
            'infrastructure' => $this->infrastructureRates($request),
            'crop' => $this->cropRates($request),
            default => ApiResponse::error('Unknown rate type.', 'UNKNOWN_RATE_TYPE', 404),
        };
    }

    private function landRates(Request $request): JsonResponse
    {
        $query = LandRate::query()
            ->with(['landType:land_type_id,name', 'landUseType:land_use_type_id,name', 'unit:unit_id,name'])
            ->where('is_active', true);

        if ($request->filled('land_type_id')) {
            $query->where('land_type_id', $request->input('land_type_id'));
        }
        if ($request->filled('land_use_type_id')) {
            $query->where('land_use_type_id', $request->input('land_use_type_id'));
        }
        if ($request->filled('unit_id')) {
            $query->where('unit_id', $request->input('unit_id'));
        }

        $rates = $query->orderBy('rate_per_unit')->get()->map(fn (LandRate $r) => [
            'rate_id' => $r->land_rate_id,
            'rate_type' => 'land',
            'land_type_id' => $r->land_type_id,
            'land_type' => $r->landType?->name,
            'land_use_type_id' => $r->land_use_type_id,
            'land_use_type' => $r->landUseType?->name,
            'unit_id' => $r->unit_id,
            'unit' => $r->unit?->name,
            'rate_per_unit' => $r->rate_per_unit,
            'effective_date' => $r->effective_date?->toDateString(),
        ]);

        return ApiResponse::success($rates, 'OK');
    }

    private function treeRates(Request $request): JsonResponse
    {
        $query = TreeRate::query()
            ->with(['treeType:tree_type_id,name', 'treeAgeCategory:tree_age_category_id,name'])
            ->where('is_active', true);

        if ($request->filled('tree_type_id')) {
            $query->where('tree_type_id', $request->input('tree_type_id'));
        }
        if ($request->filled('tree_age_category_id')) {
            $query->where('tree_age_category_id', $request->input('tree_age_category_id'));
        }

        $rates = $query->orderBy('rate_per_tree')->get()->map(fn (TreeRate $r) => [
            'rate_id' => $r->tree_rate_id,
            'rate_type' => 'tree',
            'tree_type_id' => $r->tree_type_id,
            'tree_type' => $r->treeType?->name,
            'tree_age_category_id' => $r->tree_age_category_id,
            'tree_age_category' => $r->treeAgeCategory?->name,
            'rate_per_unit' => $r->rate_per_tree,
            'effective_date' => $r->effective_date?->toDateString(),
        ]);

        return ApiResponse::success($rates, 'OK');
    }

    private function infrastructureRates(Request $request): JsonResponse
    {
        $query = InfrastructureRate::query()
            ->with(['infrastructureType:infrastructure_type_id,name', 'usageType:usage_type_id,name'])
            ->where('is_active', true);

        if ($request->filled('infrastructure_type_id')) {
            $query->where('infrastructure_type_id', $request->input('infrastructure_type_id'));
        }
        if ($request->filled('usage_type_id')) {
            $query->where('usage_type_id', $request->input('usage_type_id'));
        }

        $rates = $query->orderBy('rate_per_sqft')->get()->map(fn (InfrastructureRate $r) => [
            'rate_id' => $r->infrastructure_rate_id,
            'rate_type' => 'infrastructure',
            'infrastructure_type_id' => $r->infrastructure_type_id,
            'infrastructure_type' => $r->infrastructureType?->name,
            'usage_type_id' => $r->usage_type_id,
            'usage_type' => $r->usageType?->name,
            'rate_per_unit' => $r->rate_per_sqft,
            'effective_date' => $r->effective_date?->toDateString(),
        ]);

        return ApiResponse::success($rates, 'OK');
    }

    private function cropRates(Request $request): JsonResponse
    {
        $query = CropRate::query()
            ->with(['cropType:crop_type_id,name', 'cropSeason:crop_season_id,name', 'unit:unit_id,name'])
            ->where('is_active', true);

        if ($request->filled('crop_type_id')) {
            $query->where('crop_type_id', $request->input('crop_type_id'));
        }
        if ($request->filled('crop_season_id')) {
            $query->where('crop_season_id', $request->input('crop_season_id'));
        }
        if ($request->filled('unit_id')) {
            $query->where('unit_id', $request->input('unit_id'));
        }

        $rates = $query->orderBy('rate_per_unit')->get()->map(fn (CropRate $r) => [
            'rate_id' => $r->crop_rate_id,
            'rate_type' => 'crop',
            'crop_type_id' => $r->crop_type_id,
            'crop_type' => $r->cropType?->name,
            'crop_season_id' => $r->crop_season_id,
            'crop_season' => $r->cropSeason?->name,
            'unit_id' => $r->unit_id,
            'unit' => $r->unit?->name,
            'rate_per_unit' => $r->rate_per_unit,
            'effective_date' => $r->effective_date?->toDateString(),
        ]);

        return ApiResponse::success($rates, 'OK');
    }
}
