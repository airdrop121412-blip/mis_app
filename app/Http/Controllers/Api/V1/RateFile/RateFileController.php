<?php

namespace App\Http\Controllers\Api\V1\RateFile;

use App\Http\Controllers\Controller;
use App\Models\BaseModel;
use App\Models\Infrastructure;
use App\Models\Land;
use App\Models\RateFileLand;
use App\Models\RateFileStructure;
use App\Models\RateFileTree;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * EC Rate File CRUD (Admin → Rate Management).
 *
 * Routes: /api/v1/rate-file/{type}   (type ∈ land | structure | tree)
 *   GET    /rate-file/{type}             — list rates (permission: master.view)
 *   POST   /rate-file/{type}             — create (permission: master.manage)
 *   PUT    /rate-file/{type}/{id}        — update (permission: master.manage)
 *   DELETE /rate-file/{type}/{id}        — delete (permission: master.manage)
 *
 * These are the reference rates the EC File engine uses when valuing land,
 * structure and tree compensation. Writing requires the same admin
 * permission as master / project management.
 */
class RateFileController extends Controller
{
    /** Map of rate file type → model class (keeps the per-type code local). */
    private const TYPE_MODEL = [
        'land' => RateFileLand::class,
        'structure' => RateFileStructure::class,
        'tree' => RateFileTree::class,
    ];

    /**
     * GET /api/v1/rate-file/{type}
     *
     * Returns rates ordered for browsing. district/upazila are resolved to
     * their names where present so the admin UI needs no joins.
     */
    public function index(Request $request, string $type): JsonResponse
    {
        $model = $this->model($type);

        $query = $model::query();

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->input('project_id'));
        }

        if ($model === RateFileStructure::class || $model === RateFileTree::class) {
            $query->with(['district:district_id,name', 'upazila:upazila_id,name']);
        }

        $this->applyDefaultOrder($query, $model);

        $rows = $query->get()->map(fn (BaseModel $row) => $this->present($row))->values();

        return ApiResponse::success($rows, 'OK');
    }

    /**
     * POST /api/v1/rate-file/{type}
     */
    public function store(Request $request, string $type): JsonResponse
    {
        $model = $this->model($type);

        $validated = $request->validate($this->rules($type));
        $entity = $model::create($validated);

        return ApiResponse::created($this->present($entity), ucfirst($type).' rate created.');
    }

    /**
     * PUT /api/v1/rate-file/{type}/{id}
     */
    public function update(Request $request, string $type, string $id): JsonResponse
    {
        $model = $this->model($type);

        $entity = $model::query()
            ->where($model->getKeyName(), $id)
            ->firstOrFail();
        $entity->update($request->validate($this->rules($type)));

        return ApiResponse::success($this->present($entity->fresh()), ucfirst($type).' rate updated.');
    }

    /**
     * DELETE /api/v1/rate-file/{type}/{id}
     */
    public function destroy(string $type, string $id): JsonResponse
    {
        $model = $this->model($type);

        $entity = $model::query()
            ->where($model->getKeyName(), $id)
            ->firstOrFail();
        $entity->delete();

        return ApiResponse::success(null, ucfirst($type).' rate deleted.');
    }

    /**
     * GET /api/v1/rate-file/material-options
     *
     * Collects every distinct "Other" → manually typed material from the
     * Entry forms (Step 5 → Structure Material*) so the admin can pick them
     * directly when building structure rates.
     */
    public function materialOptions(): JsonResponse
    {
        $custom = Infrastructure::query()
            ->where('structure_material', 'Other')
            ->whereNotNull('structure_material_other')
            ->distinct()
            ->pluck('structure_material_other')
            ->map(static fn (mixed $v): string => (string) trim($v))
            ->filter(static fn (string $v): bool => $v !== '')
            ->unique()
            ->values();

        return ApiResponse::success($custom, 'OK');
    }

    /**
     * GET /api/v1/rate-file/usage-type-options
     *
     * Collects every distinct "Other" → manually typed usage type from the
     * Entry forms (Step 5 → Structure Information → Usage Type*), so the
     * admin can pick the exact values entered instead of a generic "Other".
     */
    public function usageTypeOptions(): JsonResponse
    {
        $custom = Infrastructure::query()
            ->whereNotNull('usage_type_other')
            ->distinct()
            ->pluck('usage_type_other')
            ->map(static fn (mixed $v): string => (string) trim($v))
            ->filter(static fn (string $v): bool => $v !== '')
            ->unique()
            ->values();

        return ApiResponse::success($custom, 'OK');
    }

    /**
     * GET /api/v1/rate-file/mouza-options
     *
     * Collects every distinct mouza from the Entry forms (Step 3 → Land
     * Information → Mouza) so the admin can pick from real mouzas.
     */
    public function mouzaOptions(): JsonResponse
    {
        $mouzas = Land::query()
            ->whereNotNull('mouza')
            ->distinct()
            ->pluck('mouza')
            ->map(static fn (mixed $v): string => (string) trim($v))
            ->filter(static fn (string $v): bool => $v !== '')
            ->unique()
            ->values();

        return ApiResponse::success($mouzas, 'OK');
    }

    /**
     * GET /api/v1/rate-file/land-category-options
     *
     * Collects every distinct Land Category the admin can pick from — the
     * categories already priced in the Rates File (rate_file_land) plus any
     * "Other" → manually typed Land Type from the Entry forms (Step 3 → Land
     * Information → Land Type*). Union keeps the DA category list in sync
     * with what the EC engine can actually value.
     */
    public function landCategoryOptions(): JsonResponse
    {
        $fromRates = RateFileLand::query()
            ->whereNotNull('land_category')
            ->distinct()
            ->pluck('land_category')
            ->map(static fn (mixed $v): string => (string) trim($v))
            ->filter(static fn (string $v): bool => $v !== '')
            ->unique()
            ->values();

        $fromEntries = Land::query()
            ->whereNotNull('land_type_other')
            ->distinct()
            ->pluck('land_type_other')
            ->map(static fn (mixed $v): string => (string) trim($v))
            ->filter(static fn (string $v): bool => $v !== '')
            ->unique()
            ->values();

        return ApiResponse::success(
            $fromRates->merge($fromEntries)->unique()->values(),
            'OK',
        );
    }

    // ── Internals ───────────────────────────────────────────────

    private function model(string $type): string
    {
        if (! array_key_exists($type, self::TYPE_MODEL)) {
            abort(404, 'Unknown rate file type.');
        }

        return self::TYPE_MODEL[$type];
    }

    private function rules(string $type): array
    {
        return match ($type) {
            'land' => [
                'project_id' => 'required|integer|exists:project,project_id',
                'mouza' => 'required|string|max:255',
                'land_category' => 'required|string|max:255',
                'dc_office_rate' => 'required|numeric|min:0',
                'pavc_rate' => 'required|numeric|min:0',
                'is_active' => 'sometimes|boolean',
            ],
            'structure' => [
                'project_id' => 'required|integer|exists:project,project_id',
                'district_id' => 'nullable|integer|exists:district,district_id',
                'upazila_id' => 'nullable|integer|exists:upazila,upazila_id',
                'material' => 'required|string|max:50',
                'usage_type' => 'required|string|max:255',
                'nature' => ['required', 'string', 'max:30', Rule::in(['Primary', 'Secondary'])],
                'unit' => ['required', 'string', 'max:20', Rule::in(['SFT', 'CFT', 'RFT', 'Number'])],
                'rate' => 'required|numeric|min:0',
                'is_active' => 'sometimes|boolean',
            ],
            'tree' => [
                'project_id' => 'required|integer|exists:project,project_id',
                'district_id' => 'nullable|integer|exists:district,district_id',
                'upazila_id' => 'nullable|integer|exists:upazila,upazila_id',
                'tree_type' => 'required|string|max:255',
                'size' => ['required', 'string', 'max:20', Rule::in(['Large', 'Medium', 'Small', 'Sapling'])],
                'rate' => 'required|numeric|min:0',
                'is_active' => 'sometimes|boolean',
            ],
        };
    }

    private function applyDefaultOrder(Builder $query, string $model): void
    {
        if ($model === RateFileStructure::class || $model === RateFileTree::class) {
            $query->orderBy('district_id')->orderBy('upazila_id');
        }

        $query->orderBy(app($model)->getKeyName());
    }

    /** Flatten a rate row into the API shape (resolved labels, `id` key). */
    private function present(BaseModel $entity): array
    {
        $isLand = $entity instanceof RateFileLand;
        $isStructure = $entity instanceof RateFileStructure;

        $base = [
            'id' => $entity->getKey(),
            'is_active' => (bool) $entity->is_active,
        ];

        if ($isLand) {
            return $base + [
                'mouza' => $entity->mouza,
                'land_category' => $entity->land_category,
                'dc_office_rate' => $entity->dc_office_rate,
                'pavc_rate' => $entity->pavc_rate,
            ];
        }

        if ($isStructure) {
            return $base + [
                'district_id' => $entity->district_id,
                'district' => $entity->district?->name,
                'upazila_id' => $entity->upazila_id,
                'upazila' => $entity->upazila?->name,
                'material' => $entity->material,
                'usage_type' => $entity->usage_type,
                'nature' => $entity->nature,
                'unit' => $entity->unit,
                'rate' => $entity->rate,
            ];
        }

        // tree
        return $base + [
            'district_id' => $entity->district_id,
            'district' => $entity->district?->name,
            'upazila_id' => $entity->upazila_id,
            'upazila' => $entity->upazila?->name,
            'tree_type' => $entity->tree_type,
            'size' => $entity->size,
            'rate' => $entity->rate,
        ];
    }
}
