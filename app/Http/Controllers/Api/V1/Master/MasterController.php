<?php

namespace App\Http\Controllers\Api\V1\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Master\MasterEntityMap;
use App\Http\Requests\Api\V1\Master\MasterStoreRequest;
use App\Http\Requests\Api\V1\Master\MasterUpdateRequest;
use App\Http\Resources\Api\V1\Master\MasterResource;
use App\Models\BaseModel;
use App\Models\BusinessCategory;
use App\Models\BusinessOwnershipType;
use App\Models\BusinessType;
use App\Models\CropSeason;
use App\Models\CropType;
use App\Models\District;
use App\Models\EmploymentType;
use App\Models\Gender;
use App\Models\InfrastructureType;
use App\Models\LandDamageType;
use App\Models\LandStatus;
use App\Models\LandType;
use App\Models\LandUseType;
use App\Models\LocationSource;
use App\Models\Occupation;
use App\Models\OwnershipType;
use App\Models\PaymentMethod;
use App\Models\Relationship;
use App\Models\Religion;
use App\Models\ResidentialCommercialType;
use App\Models\TenantType;
use App\Models\TreeAgeCategory;
use App\Models\TreeType;
use App\Models\Unit;
use App\Models\Upazila;
use App\Models\UsageType;
use App\Models\Village;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Generic CRUD controller for the 27 master lookup tables (architecture §5.2).
 *
 * One controller handles all master entities via a whitelist-validated
 * {entity} route parameter. Each entity is mapped to its Eloquent model,
 * primary key, and whether it carries a `code` column.
 *
 * Routes: /api/v1/masters/{entity} and /api/v1/masters/{entity}/{id}
 */
class MasterController extends Controller
{
    /**
     * Whitelist of allowed entity slugs → model + PK + flags.
     *
     * @var array<string, array{model: class-string, pk: string, has_code: bool, parent_fk?: array{table: string, key: string, column: string}}>
     */
    private const ENTITIES = [
        'district' => ['model' => District::class, 'pk' => 'district_id', 'has_code' => true],
        'upazila' => ['model' => Upazila::class, 'pk' => 'upazila_id', 'has_code' => true, 'parent_fk' => ['table' => 'district', 'key' => 'district_id', 'column' => 'district_id']],
        'village' => ['model' => Village::class, 'pk' => 'village_id', 'has_code' => false, 'parent_fk' => ['table' => 'upazila', 'key' => 'upazila_id', 'column' => 'upazila_id']],
        'religion' => ['model' => Religion::class, 'pk' => 'religion_id', 'has_code' => true],
        'gender' => ['model' => Gender::class, 'pk' => 'gender_id', 'has_code' => true],
        'occupation' => ['model' => Occupation::class, 'pk' => 'occupation_id', 'has_code' => true],
        'relationship' => ['model' => Relationship::class, 'pk' => 'relationship_id', 'has_code' => true],
        'land_status' => ['model' => LandStatus::class, 'pk' => 'land_status_id', 'has_code' => true],
        'residential_commercial_type' => ['model' => ResidentialCommercialType::class, 'pk' => 'rc_type_id', 'has_code' => true],
        'tenant_type' => ['model' => TenantType::class, 'pk' => 'tenant_type_id', 'has_code' => true],
        'land_type' => ['model' => LandType::class, 'pk' => 'land_type_id', 'has_code' => true],
        'ownership_type' => ['model' => OwnershipType::class, 'pk' => 'ownership_type_id', 'has_code' => true],
        'land_use_type' => ['model' => LandUseType::class, 'pk' => 'land_use_type_id', 'has_code' => true],
        'land_damage_type' => ['model' => LandDamageType::class, 'pk' => 'land_damage_type_id', 'has_code' => true],
        'crop_type' => ['model' => CropType::class, 'pk' => 'crop_type_id', 'has_code' => true],
        'crop_season' => ['model' => CropSeason::class, 'pk' => 'crop_season_id', 'has_code' => true],
        'tree_type' => ['model' => TreeType::class, 'pk' => 'tree_type_id', 'has_code' => true],
        'tree_age_category' => ['model' => TreeAgeCategory::class, 'pk' => 'tree_age_category_id', 'has_code' => true],
        'infrastructure_type' => ['model' => InfrastructureType::class, 'pk' => 'infrastructure_type_id', 'has_code' => true],
        'usage_type' => ['model' => UsageType::class, 'pk' => 'usage_type_id', 'has_code' => true],
        'business_type' => ['model' => BusinessType::class, 'pk' => 'business_type_id', 'has_code' => true],
        'business_category' => ['model' => BusinessCategory::class, 'pk' => 'business_category_id', 'has_code' => true],
        'business_ownership_type' => ['model' => BusinessOwnershipType::class, 'pk' => 'business_ownership_type_id', 'has_code' => true],
        'employment_type' => ['model' => EmploymentType::class, 'pk' => 'employment_type_id', 'has_code' => true],
        'unit' => ['model' => Unit::class, 'pk' => 'unit_id', 'has_code' => true],
        'payment_method' => ['model' => PaymentMethod::class, 'pk' => 'payment_method_id', 'has_code' => true],
        'location_source' => ['model' => LocationSource::class, 'pk' => 'location_source_id', 'has_code' => true],
    ];

    /**
     * GET /api/v1/masters/{entity}
     *
     * Paginated list, filterable by ?is_active=1 and ?q= (search name/code).
     * Hierarchical masters: ?district_id= on upazila, ?upazila_id= on village.
     */
    public function index(Request $request, string $entity): JsonResponse
    {
        $config = $this->resolveEntity($entity);
        $query = $config['model']::query();

        // Filter: is_active.
        if ($request->filled('is_active')) {
            $query->where('is_active', (int) $request->boolean('is_active'));
        }

        // Search: name or code.
        if ($request->filled('q')) {
            $search = $request->input('q');
            $query->where(function ($q) use ($search, $config) {
                $q->where('name', 'like', "%{$search}%");
                if ($config['has_code']) {
                    $q->orWhere('code', 'like', "%{$search}%");
                }
            });
        }

        // Hierarchical filters.
        if ($entity === 'upazila' && $request->filled('district_id')) {
            $query->where('district_id', $request->input('district_id'));
        }
        if ($entity === 'village' && $request->filled('upazila_id')) {
            $query->where('upazila_id', $request->input('upazila_id'));
        }

        // Default sort: name ascending (tree_age_category / business_category by sort_order if present).
        if (in_array($entity, ['tree_age_category', 'business_category'])) {
            $query->orderBy('sort_order')->orderBy('name');
        } else {
            $query->orderBy('name');
        }

        $perPage = min((int) $request->input('per_page', 25), 100);

        $paginator = $query->paginate($perPage);

        return ApiResponse::success(
            MasterResource::collection($paginator),
            'OK',
            200,
            [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        );
    }

    /**
     * GET /api/v1/masters/{entity}/{id}
     */
    public function show(string $entity, string $id): JsonResponse
    {
        $config = $this->resolveEntity($entity);
        $record = $config['model']::findOrFail($id);

        return ApiResponse::success(new MasterResource($record), 'OK');
    }

    /**
     * POST /api/v1/masters/{entity}
     *
     * Returns 201 + Location header.
     */
    public function store(MasterStoreRequest $request, string $entity): JsonResponse
    {
        $config = $this->resolveEntity($entity);

        $data = $request->validated();

        /** @var BaseModel $record */
        $record = $config['model']::create($data);

        return ApiResponse::created(
            new MasterResource($record),
            Str::studly($entity).' created.',
        )->header('Location', route('masters.show', ['entity' => $entity, 'id' => $record->{$config['pk']}]));
    }

    /**
     * PUT /api/v1/masters/{entity}/{id}
     */
    public function update(MasterUpdateRequest $request, string $entity, string $id): MasterResource|JsonResponse
    {
        $config = $this->resolveEntity($entity);
        $record = $config['model']::findOrFail($id);

        $data = $request->validated();

        $record->update($data);

        return ApiResponse::success(
            new MasterResource($record->fresh()),
            Str::studly($entity).' updated.',
        );
    }

    /**
     * DELETE /api/v1/masters/{entity}/{id}
     *
     * Soft deactivate: sets is_active = 0 (never hard-deletes master data).
     */
    public function destroy(string $entity, string $id): JsonResponse
    {
        $config = $this->resolveEntity($entity);
        $record = $config['model']::findOrFail($id);

        if (! $record->is_active) {
            return ApiResponse::error(
                Str::studly($entity).' is already deactivated.',
                'ALREADY_DEACTIVATED',
                422,
            );
        }

        $record->update(['is_active' => false]);

        return ApiResponse::success(
            null,
            Str::studly($entity).' deactivated.',
        );
    }

    // ──────────────────────────────────────────────────────────────
    // Hierarchy shortcuts (architecture §5.2 #12–13)
    // ──────────────────────────────────────────────────────────────

    /**
     * GET /api/v1/masters/upazilas?district_id=
     *
     * Returns upazilas filtered by district_id. Delegates to index().
     */
    public function upazilas(Request $request): JsonResponse
    {
        return $this->index($request, 'upazila');
    }

    /**
     * GET /api/v1/masters/villages?upazila_id=
     *
     * Returns villages filtered by upazila_id. Delegates to index().
     */
    public function villages(Request $request): JsonResponse
    {
        return $this->index($request, 'village');
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Resolve entity config or abort 404.
     *
     * @return array{model: class-string, pk: string, has_code: bool, parent_fk?: array}
     */
    private function resolveEntity(string $entity): array
    {
        if (! isset(MasterEntityMap::ENTITIES[$entity])) {
            abort(404, "Unknown master entity: {$entity}");
        }

        return MasterEntityMap::ENTITIES[$entity];
    }
}
