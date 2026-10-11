<?php

namespace App\Http\Controllers\Api\V1\Ep\Nested;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AffectedPerson;
use App\Models\Infrastructure;
use App\Services\Ep\AdditionalPaymentRecordService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InfrastructureController extends Controller
{
    public function index(Request $request, AffectedPerson $ep): JsonResponse
    {
        $query = Infrastructure::where('affected_person_id', $ep->affected_person_id);

        $perPage = min((int) $request->input('per_page', 25), 100);
        $paginator = $query->with([
            'infrastructureType:infrastructure_type_id,name',
            'usageType:usage_type_id,name',
        ])->orderByDesc('infrastructure_id')->paginate($perPage);

        $paginator->getCollection()->transform(function (Infrastructure $infra): Infrastructure {
            return $infra->setAttribute('infrastructure_type', $infra->infrastructureType?->name)
                ->setAttribute('usage_type', $infra->usageType?->name)
                ->unsetRelation('infrastructureType')
                ->unsetRelation('usageType');
        });
        $paginator->setCollection(app(AdditionalPaymentRecordService::class)
            ->decorate($paginator->getCollection(), 'infrastructure'));

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

        $data = $request->validate([
            'infrastructure_type_id' => 'required|integer',
            'infrastructure_type_other' => 'nullable|string|max:255',
            'usage_type_id' => 'required|integer',
            'usage_type_other' => 'nullable|string|max:255',
            'structure_nature' => ['required', 'string', Rule::in(['Primary', 'Secondary'])],
            'structure_material' => ['required', 'string', Rule::in(['Pacca', 'Semi Pacca', 'Tin Made', 'Katcha', 'Thatched', 'Other'])],
            'structure_material_other' => ['nullable', 'string', 'max:255', 'required_if:structure_material,Other'],
            'unit' => ['nullable', 'string', Rule::in(['SFT', 'CFT', 'RFT', 'Number'])],
            'length' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'area_sqft' => 'required|numeric|min:0',
            'damage_percentage' => 'required|numeric|min:0|max:100',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $data['affected_person_id'] = $ep->affected_person_id;

        $entity = Infrastructure::create($data);
        app(AdditionalPaymentRecordService::class)->register($entity, $ep);

        return ApiResponse::created($entity, 'Infrastructure created.');
    }

    public function show(AffectedPerson $ep, string $id): JsonResponse
    {
        $entity = Infrastructure::where('infrastructure_id', $id)
            ->where('affected_person_id', $ep->affected_person_id)
            ->firstOrFail();

        return ApiResponse::success($entity, 'OK');
    }

    public function update(Request $request, AffectedPerson $ep, string $id): JsonResponse
    {
        $entity = Infrastructure::where('infrastructure_id', $id)
            ->where('affected_person_id', $ep->affected_person_id)
            ->firstOrFail();
        app(AdditionalPaymentRecordService::class)->assertEditable($ep, $entity, 'infrastructure');

        $data = $request->validate([
            'infrastructure_type_id' => 'required|integer',
            'infrastructure_type_other' => 'nullable|string|max:255',
            'usage_type_id' => 'required|integer',
            'usage_type_other' => 'nullable|string|max:255',
            'structure_nature' => ['required', 'string', Rule::in(['Primary', 'Secondary'])],
            'structure_material' => ['required', 'string', Rule::in(['Pacca', 'Semi Pacca', 'Tin Made', 'Katcha', 'Thatched', 'Other'])],
            'structure_material_other' => ['nullable', 'string', 'max:255', 'required_if:structure_material,Other'],
            'unit' => ['nullable', 'string', Rule::in(['SFT', 'CFT', 'RFT', 'Number'])],
            'length' => 'nullable|numeric|min:0',
            'width' => 'nullable|numeric|min:0',
            'area_sqft' => 'required|numeric|min:0',
            'damage_percentage' => 'required|numeric|min:0|max:100',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $entity->update($data);

        return ApiResponse::success($entity->fresh(), 'Infrastructure updated.');
    }

    public function destroy(AffectedPerson $ep, string $id): JsonResponse
    {
        $entity = Infrastructure::where('infrastructure_id', $id)
            ->where('affected_person_id', $ep->affected_person_id)
            ->firstOrFail();
        app(AdditionalPaymentRecordService::class)->assertEditable($ep, $entity, 'infrastructure');

        $entity->delete();

        return ApiResponse::success(null, 'Infrastructure deleted.');
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
                'EP must be in DRAFT status to modify infrastructure records.',
                'EP_NOT_EDITABLE',
            );
        }
    }
}
