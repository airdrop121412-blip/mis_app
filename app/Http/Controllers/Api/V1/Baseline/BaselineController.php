<?php

namespace App\Http\Controllers\Api\V1\Baseline;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Ep\AffectedPersonResource;
use App\Models\AffectedPerson;
use App\Models\Business;
use App\Models\Crop;
use App\Models\FamilyMember;
use App\Models\Infrastructure;
use App\Models\Land;
use App\Models\Tenant;
use App\Models\Tree;
use App\Models\Worker;
use App\Support\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cross-EP "Baseline" browser (architecture §5.4–§5.11).
 *
 * Each endpoint returns a project-scoped, searchable, paginated list of
 * records for one baseline table. Rows carry a flat `ep_id`/`ep_name`
 * context from the owning Affected Person, and name master lookups are
 * resolved to strings (e.g. gender → "Male").
 */
class BaselineController extends Controller
{
    /**
     * GET /api/v1/baseline/eps
     *
     * EP Details — the full affected_person list. Shares the shape of
     * AffectedPersonResource so every field is available; optional
     * `has_gps=1` restricts to records with GPS coordinates.
     */
    public function eps(Request $request): JsonResponse
    {
        $query = AffectedPerson::query()
            ->with(['gender:gender_id,name', 'religion:religion_id,name', 'occupation:occupation_id,name',
                'district:district_id,name', 'upazila:upazila_id,name', 'landStatus:land_status_id,name', 'rcType:rc_type_id,name']);

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->input('project_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->boolean('has_gps')) {
            $query->where(function ($q) {
                $q->whereNotNull('gps_latitude')->where('gps_latitude', '!=', '')
                    ->orWhereNotNull('gps_longitude')->where('gps_longitude', '!=', '');
            });
        }

        $search = $this->search($request);
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('ep_id', 'like', "%{$search}%")
                    ->orWhere('hh_id', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('father_name', 'like', "%{$search}%")
                    ->orWhere('mother_name', 'like', "%{$search}%")
                    ->orWhere('spouse_name', 'like', "%{$search}%")
                    ->orWhere('nid_number', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%")
                    ->orWhere('mouza', 'like', "%{$search}%")
                    ->orWhere('union_name', 'like', "%{$search}%");
            });
        }

        $this->applySort($request, $query, $this->epsSortMap());

        $perPage = min((int) $request->input('per_page', 25), 100);
        $paginator = $query->paginate($perPage);

        return ApiResponse::success(
            AffectedPersonResource::collection($paginator),
            'OK',
            200,
            ['pagination' => $this->pagination($paginator)],
        );
    }

    /**
     * GET /api/v1/baseline/family-members
     */
    public function familyMembers(Request $request): JsonResponse
    {
        return $this->paginateEntities(
            $request,
            FamilyMember::class,
            ['relationship:relationship_id,name', 'gender:gender_id,name', 'occupation:occupation_id,name',
                'affectedPerson:affected_person_id,ep_id,name,hh_id'],
            ['family_member_id', 'name', 'relationship_other', 'gender_other', 'marital_status', 'education',
                'education_other', 'occupation_other', 'health_status', 'disability_type', 'remarks'],
            'family_member_id',
            function (FamilyMember $row) {
                $row->setAttribute('relationship', $row->relationship?->name)
                    ->setAttribute('gender', $row->gender?->name)
                    ->setAttribute('occupation', $row->occupation?->name);
                $this->attachEpContext($row);

                return $row->unsetRelation('relationship')->unsetRelation('gender')->unsetRelation('occupation');
            },
            'affectedPerson',
            $this->familySortMap(),
        );
    }

    /**
     * GET /api/v1/baseline/lands
     */
    public function lands(Request $request): JsonResponse
    {
        return $this->paginateEntities(
            $request,
            Land::class,
            ['landType:land_type_id,name', 'landStatus:land_status_id,name', 'ownershipType:ownership_type_id,name',
                'district:district_id,name', 'upazila:upazila_id,name', 'landAreaUnit:unit_id,name',
                'landUseType:land_use_type_id,name', 'affectedPerson:affected_person_id,ep_id,name,hh_id'],
            ['mouza', 'khatian_number', 'dag_number', 'la_case_no', 'land_type_other', 'ownership_type_other',
                'land_use_type_other', 'remarks'],
            'land_id',
            function (Land $row) {
                $row->setAttribute('land_type', $row->landType?->name)
                    ->setAttribute('land_status', $row->landStatus?->name)
                    ->setAttribute('ownership_type', $row->ownershipType?->name)
                    ->setAttribute('district', $row->district?->name)
                    ->setAttribute('upazila', $row->upazila?->name)
                    ->setAttribute('land_area_unit', $row->landAreaUnit?->name)
                    ->setAttribute('land_use_type', $row->landUseType?->name);
                $this->attachEpContext($row);

                return $row->unsetRelation('landType')->unsetRelation('landStatus')->unsetRelation('ownershipType')
                    ->unsetRelation('district')->unsetRelation('upazila')->unsetRelation('landAreaUnit')
                    ->unsetRelation('landUseType');
            },
            'affectedPerson',
            $this->landsSortMap(),
        );
    }

    /**
     * GET /api/v1/baseline/crops
     */
    public function crops(Request $request): JsonResponse
    {
        return $this->paginateEntities(
            $request,
            Crop::class,
            ['cropType:crop_type_id,name', 'cropSeason:crop_season_id,name', 'areaUnit:unit_id,name',
                'land:land_id,mouza,affected_person_id', 'land.affectedPerson:affected_person_id,ep_id,name,hh_id'],
            ['crop_type_other', 'remarks'],
            'crop_id',
            function (Crop $row) {
                $row->setAttribute('crop_type', $row->cropType?->name)
                    ->setAttribute('crop_season', $row->cropSeason?->name)
                    ->setAttribute('area_unit', $row->areaUnit?->name)
                    ->setAttribute('mouza', $row->land?->mouza)
                    ->setAttribute('affected_person_id', $row->land?->affectedPerson?->affected_person_id)
                    ->setAttribute('ep_id', $row->land?->affectedPerson?->ep_id)
                    ->setAttribute('ep_name', $row->land?->affectedPerson?->name)
                    ->setAttribute('hh_id', $row->land?->affectedPerson?->hh_id);

                return $row->unsetRelation('cropType')->unsetRelation('cropSeason')->unsetRelation('areaUnit')
                    ->unsetRelation('land');
            },
            'land.affectedPerson',
            $this->cropsSortMap(),
        );
    }

    /**
     * GET /api/v1/baseline/infrastructures
     */
    public function infrastructures(Request $request): JsonResponse
    {
        return $this->paginateEntities(
            $request,
            Infrastructure::class,
            ['infrastructureType:infrastructure_type_id,name', 'usageType:usage_type_id,name',
                'affectedPerson:affected_person_id,ep_id,name,hh_id'],
            ['infrastructure_type_other', 'usage_type_other', 'structure_nature', 'structure_material',
                'structure_material_other', 'remarks'],
            'infrastructure_id',
            function (Infrastructure $row) {
                $row->setAttribute('infrastructure_type', $row->infrastructureType?->name)
                    ->setAttribute('usage_type', $row->usageType?->name);
                $this->attachEpContext($row);

                return $row->unsetRelation('infrastructureType')->unsetRelation('usageType');
            },
            'affectedPerson',
            $this->infrastructureSortMap(),
        );
    }

    /**
     * GET /api/v1/baseline/trees
     */
    public function trees(Request $request): JsonResponse
    {
        return $this->paginateEntities(
            $request,
            Tree::class,
            ['treeType:tree_type_id,name', 'treeAgeCategory:tree_age_category_id,name',
                'affectedPerson:affected_person_id,ep_id,name,hh_id'],
            ['name', 'tree_type_other', 'land_ownership', 'remarks'],
            'tree_id',
            function (Tree $row) {
                $row->setAttribute('tree_type', $row->treeType?->name)
                    ->setAttribute('tree_age_category', $row->treeAgeCategory?->name);
                $this->attachEpContext($row);

                return $row->unsetRelation('treeType')->unsetRelation('treeAgeCategory');
            },
            'affectedPerson',
            $this->treesSortMap(),
        );
    }

    /**
     * GET /api/v1/baseline/tenants
     */
    public function tenants(Request $request): JsonResponse
    {
        return $this->paginateEntities(
            $request,
            Tenant::class,
            ['tenantType:tenant_type_id,name', 'gender:gender_id,name', 'district:district_id,name',
                'upazila:upazila_id,name', 'infrastructure.infrastructureType:infrastructure_type_id,name',
                'affectedPerson:affected_person_id,ep_id,name,hh_id'],
            ['ep_id', 'hh_id', 'name', 'father_name', 'nid_number', 'mobile', 'mouza', 'union_name',
                'address', 'agreement_number', 'remarks'],
            'tenant_id',
            function (Tenant $row) {
                $row->setAttribute('tenant_type', $row->tenantType?->name)
                    ->setAttribute('gender', $row->gender?->name)
                    ->setAttribute('district', $row->district?->name)
                    ->setAttribute('upazila', $row->upazila?->name)
                    ->setAttribute('infrastructure_type', $row->infrastructure?->infrastructureType?->name)
                    ->setAttribute('ep_name', $row->affectedPerson?->name)
                    ->setAttribute('main_ep_id', $row->affectedPerson?->ep_id);

                return $row->unsetRelation('tenantType')->unsetRelation('gender')
                    ->unsetRelation('district')->unsetRelation('upazila')
                    ->unsetRelation('infrastructure')->unsetRelation('affectedPerson');
            },
            'affectedPerson',
            $this->tenantsSortMap(),
        );
    }

    /**
     * GET /api/v1/baseline/businesses
     */
    public function businesses(Request $request): JsonResponse
    {
        return $this->paginateEntities(
            $request,
            Business::class,
            ['businessType:business_type_id,name', 'businessCategory:business_category_id,name',
                'businessOwnershipType:business_ownership_type_id,name', 'affectedPerson:affected_person_id,ep_id,name,hh_id'],
            ['business_name', 'present_address', 'ownership_code', 'income_tax_id', 'business_type_other', 'remarks'],
            'business_id',
            function (Business $row) {
                $row->setAttribute('business_type', $row->businessType?->name)
                    ->setAttribute('business_category', $row->businessCategory?->name)
                    ->setAttribute('business_ownership_type', $row->businessOwnershipType?->name);
                $this->attachEpContext($row);

                return $row->unsetRelation('businessType')->unsetRelation('businessCategory')
                    ->unsetRelation('businessOwnershipType');
            },
            'affectedPerson',
            $this->businessesSortMap(),
        );
    }

    /**
     * GET /api/v1/baseline/workers
     */
    public function workers(Request $request): JsonResponse
    {
        return $this->paginateEntities(
            $request,
            Worker::class,
            ['gender:gender_id,name', 'occupation:occupation_id,name', 'employmentType:employment_type_id,name',
                'business:business_id,business_name', 'affectedPerson:affected_person_id,ep_id,name,hh_id',
                'district:district_id,name', 'upazila:upazila_id,name'],
            ['ep_id', 'name', 'father_name', 'mobile', 'nid_number', 'mouza', 'union_name',
                'occupation_other', 'employment_type_other', 'remarks'],
            'worker_id',
            function (Worker $row) {
                $row->setAttribute('gender', $row->gender?->name)
                    ->setAttribute('occupation', $row->occupation?->name)
                    ->setAttribute('employment_type', $row->employmentType?->name)
                    ->setAttribute('business_name', $row->business?->business_name)
                    ->setAttribute('district_name', $row->district?->name)
                    ->setAttribute('upazila_name', $row->upazila?->name)
                    ->setAttribute('ep_name', $row->affectedPerson?->name)
                    ->setAttribute('main_ep_id', $row->affectedPerson?->ep_id)
                    ->setAttribute('hh_id', $row->affectedPerson?->hh_id);

                return $row->unsetRelation('gender')->unsetRelation('occupation')->unsetRelation('employmentType')
                    ->unsetRelation('business')->unsetRelation('district')->unsetRelation('upazila')
                    ->unsetRelation('affectedPerson');
            },
            'affectedPerson',
            $this->workersSortMap(),
        );
    }

    /**
     * GET /api/v1/baseline/gps
     *
     * GPS Location — EPs carrying coordinates, mirroring the eps endpoint.
     */
    public function gps(Request $request): JsonResponse
    {
        $request->merge(['has_gps' => 1]);

        return $this->eps($request);
    }

    // ── Shared plumbing ───────────────────────────────────────────

    /**
     * Run a project-scoped + searchable query for a nested baseline table.
     * Search matches table columns plus the parent EP's ep_id/name.
     *
     * @param  class-string<Model>  $model
     * @param  list<string>  $with
     * @param  list<string>  $searchCols
     * @param  callable(mixed): mixed  $resolve
     * @param  string  $orderCol  legacy default order column (unused; see $sortMap)
     * @param  callable(mixed): mixed  $resolve
     * @param  string  $epPath  relation path to the owning Affected Person
     * @param  list<array<string, mixed>>  $sortMap  sortable key → column spec
     */
    private function paginateEntities(Request $request, string $model, array $with, array $searchCols, string $orderCol, callable $resolve, string $epPath = 'affectedPerson', array $sortMap = []): JsonResponse
    {
        $query = $model::query()->with($with);

        if ($request->filled('project_id')) {
            $query->whereHas($epPath, function ($ep) use ($request) {
                $ep->where('project_id', $request->input('project_id'));
            });
        }

        $search = $this->search($request);
        if ($search !== '') {
            $query->where(function ($q) use ($search, $searchCols, $epPath) {
                foreach ($searchCols as $col) {
                    $q->orWhere($col, 'like', "%{$search}%");
                }
                $q->orWhereHas($epPath, function ($ep) use ($search) {
                    $ep->where('ep_id', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%");
                });
            });
        }

        $perPage = min((int) $request->input('per_page', 25), 100);
        $this->applySort($request, $query, $sortMap);
        $paginator = $query->paginate($perPage);

        $paginator->getCollection()->transform($resolve);

        return ApiResponse::success(
            $paginator->items(),
            'OK',
            200,
            ['pagination' => $this->pagination($paginator)],
        );
    }

    private function search(Request $request): string
    {
        return trim((string) $request->input('q', ''));
    }

    private function attachEpContext(object $row): void
    {
        $ep = $row->affectedPerson ?? null;
        $row->setAttribute('ep_id', $ep?->ep_id)
            ->setAttribute('ep_name', $ep?->name)
            ->setAttribute('hh_id', $ep?->hh_id);
    }

    /**
     * Build the sortable-column map for an endpoint.
     *
     * Keys may map to: a direct model column (`column`, optional `numeric`),
     * a BelongsTo path whose `name` is shown (`relation`), or the owning EP
     * (`ep` path → hh_id / ep_id / ep_name / main_ep_id).
     *
     * @param  array<string, bool>  $direct
     * @param  array<string, string>  $relations
     * @return array<string, array{column: string, numeric?: bool, relation?: string, ep?: string}>
     */
    private function sortMap(array $direct, array $relations = [], ?string $epPath = 'affectedPerson'): array
    {
        $map = [];
        foreach ($relations as $key => $relation) {
            $map[$key] = ['relation' => $relation, 'column' => 'name'];
        }
        if ($epPath !== null) {
            $map['hh_id'] = ['ep' => $epPath, 'column' => 'hh_id', 'numeric' => true];
            $map['ep_id'] = ['ep' => $epPath, 'column' => 'ep_id'];
            $map['ep_name'] = ['ep' => $epPath, 'column' => 'name'];
            $map['main_ep_id'] = ['ep' => $epPath, 'column' => 'ep_id'];
        }
        foreach ($direct as $column => $numeric) {
            $map[(string) $column] = ['column' => $column, 'numeric' => (bool) $numeric];
        }

        return $map;
    }

    /** @return array<string, array{column: string, numeric?: bool, relation?: string, ep?: string}> */
    private function epsSortMap(): array
    {
        return $this->sortMap(
            [
                'hh_id' => true, 'ep_id' => false, 'name' => false, 'father_name' => false,
                'mother_name' => false, 'spouse_name' => false, 'nid_number' => false,
                'mobile' => false, 'age' => true, 'date_of_birth' => false, 'status' => false,
                'union_name' => false, 'mouza' => false, 'total_family_member' => true,
                'male_member' => true, 'female_member' => true, 'category_of_loss' => false,
                'ownership' => false, 'dc_compensation' => true, 'cheque_no' => false,
                'cheque_date' => false, 'yearly_income' => true, 'is_disabled' => false,
                'is_landless' => false, 'is_tribal' => false, 'has_business_loss' => false,
                'is_tenant' => false, 'total_tenant' => true,
            ],
            ['gender' => 'gender', 'religion' => 'religion', 'occupation' => 'occupation',
                'district' => 'district', 'upazila' => 'upazila'],
            null,
        );
    }

    /** @return array<string, array{column: string, numeric?: bool, relation?: string, ep?: string}> */
    private function familySortMap(): array
    {
        return $this->sortMap(
            [
                'family_member_id' => true, 'name' => false, 'marital_status' => false,
                'education' => false, 'does_work' => false, 'is_dependent' => false,
                'health_status' => false, 'disability_type' => false,
                'age' => true, 'annual_income' => true, 'remarks' => false,
            ],
            ['relationship' => 'relationship', 'gender' => 'gender', 'occupation' => 'occupation'],
        );
    }

    /** @return array<string, array{column: string, numeric?: bool, relation?: string, ep?: string}> */
    private function landsSortMap(): array
    {
        return $this->sortMap(
            [
                'land_id' => true, 'khatian_number' => false, 'dag_number' => false,
                'la_case_no' => false, 'mouza' => false, 'land_area' => true,
                'dc_pay_amount' => true, 'dc_cheque_no' => false, 'dc_pay_date' => false,
                'land_type_other' => false, 'ownership_type_other' => false,
                'land_use_type_other' => false, 'remarks' => false,
            ],
            ['land_type' => 'landType', 'land_status' => 'landStatus', 'ownership_type' => 'ownershipType',
                'district' => 'district', 'upazila' => 'upazila', 'land_area_unit' => 'landAreaUnit',
                'land_use_type' => 'landUseType'],
        );
    }

    /** @return array<string, array{column: string, numeric?: bool, relation?: string, ep?: string}> */
    private function cropsSortMap(): array
    {
        $map = $this->sortMap(
            [
                'crop_id' => true, 'cultivated_area' => true, 'damage_percentage' => true,
                'crop_type_other' => false, 'remarks' => false,
            ],
            ['crop_type' => 'cropType', 'crop_season' => 'cropSeason', 'area_unit' => 'areaUnit'],
            'land.affectedPerson',
        );
        $map['mouza'] = ['relation' => 'land', 'column' => 'mouza'];

        return $map;
    }

    /** @return array<string, array{column: string, numeric?: bool, relation?: string, ep?: string}> */
    private function infrastructureSortMap(): array
    {
        return $this->sortMap(
            [
                'infrastructure_id' => true, 'length' => true, 'width' => true,
                'area_sqft' => true, 'damage_percentage' => true, 'structure_nature' => false,
                'structure_material' => false, 'structure_material_other' => false,
                'infrastructure_type_other' => false, 'usage_type_other' => false, 'remarks' => false,
            ],
            ['infrastructure_type' => 'infrastructureType', 'usage_type' => 'usageType'],
        );
    }

    /** @return array<string, array{column: string, numeric?: bool, relation?: string, ep?: string}> */
    private function treesSortMap(): array
    {
        return $this->sortMap(
            [
                'tree_id' => true, 'name' => false, 'land_ownership' => false,
                'quantity' => true, 'tree_type_other' => false, 'remarks' => false,
            ],
            ['tree_type' => 'treeType', 'tree_age_category' => 'treeAgeCategory'],
        );
    }

    /** @return array<string, array{column: string, numeric?: bool, relation?: string, ep?: string}> */
    private function tenantsSortMap(): array
    {
        $map = $this->sortMap(
            [
                'tenant_id' => true, 'name' => false, 'father_name' => false,
                'nid_number' => false, 'mobile' => false, 'mouza' => false, 'union_name' => false,
                'address' => false, 'agreement_number' => false, 'monthly_rent' => true,
                'age' => true, 'remarks' => false,
            ],
            ['tenant_type' => 'tenantType', 'gender' => 'gender', 'district' => 'district', 'upazila' => 'upazila'],
        );
        $map['hh_id'] = ['column' => 'hh_id', 'numeric' => true];
        $map['ep_id'] = ['column' => 'ep_id'];
        $map['infrastructure_type'] = ['relation' => 'infrastructure.infrastructureType', 'column' => 'name'];

        return $map;
    }

    /** @return array<string, array{column: string, numeric?: bool, relation?: string, ep?: string}> */
    private function businessesSortMap(): array
    {
        return $this->sortMap(
            [
                'business_id' => true, 'business_name' => false, 'present_address' => false,
                'ownership_code' => false, 'income_tax_id' => false, 'capital_amount' => true,
                'annual_income' => true, 'number_of_wage_labour' => true, 'latitude' => false,
                'longitude' => false, 'business_type_other' => false, 'remarks' => false,
            ],
            ['business_type' => 'businessType', 'business_category' => 'businessCategory',
                'business_ownership_type' => 'businessOwnershipType'],
        );
    }

    /** @return array<string, array{column: string, numeric?: bool, relation?: string, ep?: string}> */
    private function workersSortMap(): array
    {
        $map = $this->sortMap(
            [
                'worker_id' => true, 'name' => false, 'father_name' => false, 'mobile' => false,
                'nid_number' => false, 'mouza' => false, 'union_name' => false, 'total_family_member' => true,
                'duration_of_employment_month' => true, 'age' => true, 'monthly_income' => true,
                'gender_other' => false, 'occupation_other' => false, 'employment_type_other' => false,
                'remarks' => false,
            ],
            ['gender' => 'gender', 'occupation' => 'occupation', 'employment_type' => 'employmentType'],
        );
        $map['ep_id'] = ['column' => 'ep_id'];

        return $map;
    }

    /**
     * Apply `sort_by` / `sort_direction` (default: HH ID ascending, numeric).
     * Unknown columns fall back to the same default order.
     *
     * @param  array<string, array{column: string, numeric?: bool, relation?: string, ep?: string}>  $sortMap
     */
    private function applySort(Request $request, Builder $query, array $sortMap): void
    {
        $baseTable = $query->getModel()->getTable();
        // Select only the base table's columns so joined master tables never
        // shadow identical column names (e.g. `name`) on hydrated models.
        $query->select($baseTable.'.*');

        $sortBy = (string) $request->input('sort_by', 'hh_id');
        $direction = strtolower((string) $request->input('sort_direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        $spec = $sortMap[$sortBy] ?? $sortMap['hh_id'] ?? null;
        if ($spec === null) {
            return;
        }

        $table = $baseTable;
        if (isset($spec['ep'])) {
            $table = $this->joinPath($query, $spec['ep'])->getTable();
        } elseif (isset($spec['relation'])) {
            $table = $this->joinPath($query, $spec['relation'])->getTable();
        }

        $qualified = $table.'.'.$spec['column'];
        if (! empty($spec['numeric'])) {
            $query->orderByRaw("CAST({$qualified} AS UNSIGNED) {$direction}");
        } else {
            $query->orderBy($qualified, $direction);
        }
        $query->orderBy($query->getModel()->getQualifiedKeyName());
    }

    /**
     * Left-join a dot-separated chain of BelongsTo relations so ordering can
     * reference the related master table's columns.
     */
    private function joinPath(Builder $query, string $path): BelongsTo|Model
    {
        $current = $query->getModel();
        foreach (explode('.', $path) as $segment) {
            $relation = $current->{$segment}();
            if (! $relation instanceof BelongsTo) {
                throw new \InvalidArgumentException("Sort relation must be BelongsTo: {$segment}");
            }
            $query->leftJoin(
                $relation->getRelated()->getTable(),
                $relation->getQualifiedForeignKeyName(),
                '=',
                $relation->getQualifiedOwnerKeyName(),
            );
            $current = $relation->getRelated();
        }

        return $current;
    }

    /** @param LengthAwarePaginator<mixed> $paginator */
    private function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
