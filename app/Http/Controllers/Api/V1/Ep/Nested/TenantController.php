<?php

namespace App\Http\Controllers\Api\V1\Ep\Nested;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AffectedPerson;
use App\Models\Infrastructure;
use App\Models\Tenant;
use App\Services\Ep\AdditionalPaymentRecordService;
use App\Services\EpId\EpIdService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function __construct(
        private readonly EpIdService $epIdService,
    ) {}

    public function index(Request $request, AffectedPerson $ep): JsonResponse
    {
        $query = Tenant::where('affected_person_id', $ep->affected_person_id);

        $perPage = min((int) $request->input('per_page', 25), 100);
        $paginator = $query->with([
            'tenantType:tenant_type_id,name',
            'district:district_id,name',
            'upazila:upazila_id,name',
            'gender:gender_id,name',
            'occupation:occupation_id,name',
            'infrastructures:infrastructure_id',
        ])->orderByDesc('tenant_id')->paginate($perPage);

        $paginator->getCollection()->transform(fn (Tenant $tenant): Tenant => $this->decorate($tenant));
        $paginator->setCollection(app(AdditionalPaymentRecordService::class)
            ->decorate($paginator->getCollection(), 'tenant'));

        return ApiResponse::success(
            $paginator->items(),
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

    public function store(Request $request, AffectedPerson $ep): JsonResponse
    {
        $this->assertCreate($ep);

        $data = $this->validated($request);

        $data['affected_person_id'] = $ep->affected_person_id;
        $data['hh_id'] = $data['hh_id'] ?? $ep->hh_id;
        $data['district_id'] = $data['district_id'] ?? $ep->district_id;
        $data['upazila_id'] = $data['upazila_id'] ?? $ep->upazila_id;

        $structureIds = $data['infrastructure_ids'] ?? [];
        unset($data['infrastructure_ids']);
        $this->resolveStructures($data, $structureIds, $ep);

        if ($data['ep_id'] ?? null) {
            // An ep_id is never client-supplied for a new record.
            unset($data['ep_id']);
        }

        $entity = Tenant::create([
            ...$data,
            'ep_id' => $this->epIdService->generateIndirect(
                $ep->project_id,
                $ep->district_id,
                $ep->upazila_id,
                $ep->land_status_id,
                'Tenant',
                'EP_SERIAL_TENANT',
                $ep->ownership,
                $ep->phase,
            ),
        ]);
        app(AdditionalPaymentRecordService::class)->register($entity, $ep);

        if ($structureIds !== []) {
            $entity->infrastructures()->sync($structureIds);
        }

        return ApiResponse::created($this->decorate($entity->fresh()), 'Tenant created.');
    }

    public function show(AffectedPerson $ep, string $id): JsonResponse
    {
        $entity = Tenant::where('tenant_id', $id)
            ->where('affected_person_id', $ep->affected_person_id)
            ->with([
                'tenantType:tenant_type_id,name',
                'district:district_id,name',
                'upazila:upazila_id,name',
                'gender:gender_id,name',
                'occupation:occupation_id,name',
                'infrastructures:infrastructure_id',
            ])
            ->firstOrFail();

        return ApiResponse::success($this->decorate($entity), 'OK');
    }

    public function update(Request $request, AffectedPerson $ep, string $id): JsonResponse
    {
        $entity = Tenant::where('tenant_id', $id)
            ->where('affected_person_id', $ep->affected_person_id)
            ->firstOrFail();
        app(AdditionalPaymentRecordService::class)->assertEditable($ep, $entity, 'tenant');

        $data = $this->validated($request);

        $data['hh_id'] = $data['hh_id'] ?? ($entity->hh_id ?? $ep->hh_id);
        $data['district_id'] = $data['district_id'] ?? ($entity->district_id ?? $ep->district_id);
        $data['upazila_id'] = $data['upazila_id'] ?? ($entity->upazila_id ?? $ep->upazila_id);

        $structureIds = $data['infrastructure_ids'] ?? [];
        unset($data['infrastructure_ids']);
        $this->resolveStructures($data, $structureIds, $ep);

        if (empty($entity->ep_id)) {
            $data['ep_id'] = $this->epIdService->generateIndirect(
                $ep->project_id,
                $ep->district_id,
                $ep->upazila_id,
                $ep->land_status_id,
                'Tenant',
                'EP_SERIAL_TENANT',
                $ep->ownership,
                $ep->phase,
            );
        }

        $entity->update($data);

        if ($structureIds !== []) {
            $entity->infrastructures()->sync($structureIds);
        }

        return ApiResponse::success($this->decorate($entity->fresh()), 'Tenant updated.');
    }

    public function destroy(AffectedPerson $ep, string $id): JsonResponse
    {
        $entity = Tenant::where('tenant_id', $id)
            ->where('affected_person_id', $ep->affected_person_id)
            ->firstOrFail();
        app(AdditionalPaymentRecordService::class)->assertEditable($ep, $entity, 'tenant');

        $entity->delete();

        return ApiResponse::success(null, 'Tenant deleted.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'tenant_type_id' => 'required|integer',
            'name' => 'required|string|max:255',
            'hh_id' => 'nullable|string|max:50',
            'father_name' => 'nullable|string|max:255',
            'nid_number' => 'nullable|string|max:50',
            'mobile' => 'nullable|string|max:20',
            'mouza' => 'nullable|string|max:255',
            'union_name' => 'nullable|string|max:255',
            'district_id' => 'nullable|integer',
            'upazila_id' => 'nullable|integer',
            'age' => 'nullable|integer|min:0|max:150',
            'gender_id' => 'nullable|integer',
            'occupation_id' => 'nullable|integer',
            'occupation_other' => 'nullable|string|max:255',
            'annual_income' => 'nullable|numeric|min:0',
            'infrastructure_id' => 'nullable|integer',
            'infrastructure_ids' => 'nullable|array',
            'infrastructure_ids.*' => 'integer',
            'address' => 'nullable|string',
            'agreement_number' => 'nullable|string|max:255',
            'monthly_rent' => 'nullable|numeric|min:0',
            'remarks' => 'nullable|string|max:1000',
        ]);

        if (array_key_exists('monthly_rent', $data) && $data['monthly_rent'] === null) {
            $data['monthly_rent'] = 0;
        }

        if (array_key_exists('annual_income', $data) && $data['annual_income'] === null) {
            $data['annual_income'] = 0;
        }

        if (($data['infrastructure_ids'] ?? null) === null) {
            $data['infrastructure_ids'] = [];
        }
        $data['infrastructure_ids'] = array_values(array_unique(array_map('intval', $data['infrastructure_ids'])));

        return $data;
    }

    /**
     * Preserve the primary structure on the tenant column and record every
     * rented structure on the tenant_infrastructure pivot. All selected
     * structures must belong to the EP.
     */
    private function resolveStructures(array &$data, array $structureIds, AffectedPerson $ep): void
    {
        $structureIds = array_values(array_filter($structureIds));

        if ($structureIds === []) {
            $this->assertStructureBelongsToEp($data['infrastructure_id'] ?? null, $ep);

            return;
        }

        $owned = Infrastructure::where('affected_person_id', $ep->affected_person_id)
            ->whereIn('infrastructure_id', $structureIds)
            ->pluck('infrastructure_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (count($owned) !== count($structureIds)) {
            throw new BusinessRuleException(
                'One or more selected structures do not belong to this EP.',
                'TENANT_STRUCTURE_NOT_FOUND',
            );
        }

        $data['infrastructure_ids'] = $structureIds;
        $data['infrastructure_id'] = $structureIds[0];
    }

    /** Add name attributes + rented structure ids, then drop the raw relations. */
    private function decorate(Tenant $tenant): Tenant
    {
        return $tenant->setAttribute('tenant_type', $tenant->tenantType?->name)
            ->setAttribute('district_name', $tenant->district?->name)
            ->setAttribute('upazila_name', $tenant->upazila?->name)
            ->setAttribute('gender_name', $tenant->gender?->name)
            ->setAttribute('occupation_name', $tenant->occupation?->name)
            ->setAttribute(
                'infrastructure_ids',
                $tenant->infrastructures->pluck('infrastructure_id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
            )
            ->unsetRelation('tenantType')
            ->unsetRelation('district')
            ->unsetRelation('upazila')
            ->unsetRelation('gender')
            ->unsetRelation('occupation')
            ->unsetRelation('infrastructures');
    }

    private function assertStructureBelongsToEp(mixed $infrastructureId, AffectedPerson $ep): void
    {
        if ($infrastructureId === null) {
            return;
        }

        $owned = Infrastructure::where('infrastructure_id', (int) $infrastructureId)
            ->where('affected_person_id', $ep->affected_person_id)
            ->exists();

        if (! $owned) {
            throw new BusinessRuleException(
                'The selected structure does not belong to this EP.',
                'TENANT_STRUCTURE_NOT_FOUND',
            );
        }
    }

    private function assertCreate(AffectedPerson $ep): void
    {
        if ($ep->status === 'PAID') {
            return;
        }

        $this->assertDraft($ep);
    }

    private function assertDraft(AffectedPerson $ep): void
    {
        if (! $ep->canBeEditedBy(request()->user())) {
            throw new BusinessRuleException(
                'EP must be in DRAFT status to modify tenant records.',
                'EP_NOT_EDITABLE',
            );
        }
    }
}
