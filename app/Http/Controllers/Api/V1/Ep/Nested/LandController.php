<?php

namespace App\Http\Controllers\Api\V1\Ep\Nested;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AffectedPerson;
use App\Models\Land;
use App\Services\Ep\AdditionalPaymentRecordService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LandController extends Controller
{
    public function index(Request $request, AffectedPerson $ep): JsonResponse
    {
        $query = Land::where('affected_person_id', $ep->affected_person_id);

        $perPage = min((int) $request->input('per_page', 25), 100);
        $paginator = $query->with([
            'landType:land_type_id,name',
            'landStatus:land_status_id,name',
            'ownershipType:ownership_type_id,name',
            'district:district_id,name',
            'upazila:upazila_id,name',
            'village:village_id,name',
            'landAreaUnit:unit_id,name',
            'landUseType:land_use_type_id,name',
        ])->orderByDesc('land_id')->paginate($perPage);

        $paginator->getCollection()->transform(function (Land $land): Land {
            return $land->setAttribute('land_type', $land->landType?->name)
                ->setAttribute('land_status', $land->landStatus?->name)
                ->setAttribute('ownership_type', $land->ownershipType?->name)
                ->setAttribute('district', $land->district?->name)
                ->setAttribute('upazila', $land->upazila?->name)
                ->setAttribute('village', $land->village?->name)
                ->setAttribute('land_area_unit', $land->landAreaUnit?->name)
                ->setAttribute('land_use_type', $land->landUseType?->name)
                ->unsetRelation('landType')
                ->unsetRelation('landStatus')
                ->unsetRelation('ownershipType')
                ->unsetRelation('district')
                ->unsetRelation('upazila')
                ->unsetRelation('village')
                ->unsetRelation('landAreaUnit')
                ->unsetRelation('landUseType');
        });
        $paginator->setCollection(app(AdditionalPaymentRecordService::class)
            ->decorate($paginator->getCollection(), 'land'));

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
            'land_type_id' => 'required|integer',
            'land_type_other' => 'nullable|string|max:255',
            'land_status_id' => 'nullable|integer',
            'ownership_type_id' => 'required|integer',
            'ownership_type_other' => 'nullable|string|max:255',
            'district_id' => 'nullable|integer',
            'upazila_id' => 'nullable|integer',
            'village_id' => 'nullable|integer',
            'mouza' => 'nullable|string|max:255',
            'khatian_number' => 'nullable|string|max:255',
            'dag_number' => 'nullable|string|max:255',
            'la_case_no' => 'nullable|string|max:255',
            'dc_pay_amount' => 'nullable|numeric|min:0',
            'dc_cheque_no' => 'nullable|string|max:50',
            'dc_pay_date' => 'nullable|date',
            'land_area' => 'required|numeric|min:0',
            'land_area_unit_id' => 'required|integer',
            'land_use_type_id' => 'required|integer',
            'land_use_type_other' => 'nullable|string|max:255',
            'remarks' => 'nullable|string|max:1000',
        ]);

        // The wizard sends 0 for an unselected village; treat it as none.
        if (empty($data['village_id'])) {
            $data['village_id'] = null;
        }

        // Auto-derive land_status_id from the parent EP when not provided.
        if (empty($data['land_status_id'])) {
            $data['land_status_id'] = $ep->land_status_id;
        }

        $data['affected_person_id'] = $ep->affected_person_id;

        $entity = Land::create($data);
        app(AdditionalPaymentRecordService::class)->register($entity, $ep);

        return ApiResponse::created($entity, 'Land created.');
    }

    public function show(AffectedPerson $ep, string $id): JsonResponse
    {
        $entity = Land::where('land_id', $id)
            ->where('affected_person_id', $ep->affected_person_id)
            ->firstOrFail();

        return ApiResponse::success($entity, 'OK');
    }

    public function update(Request $request, AffectedPerson $ep, string $id): JsonResponse
    {
        $entity = Land::where('land_id', $id)
            ->where('affected_person_id', $ep->affected_person_id)
            ->firstOrFail();
        app(AdditionalPaymentRecordService::class)->assertEditable($ep, $entity, 'land');

        $data = $request->validate([
            'land_type_id' => 'required|integer',
            'land_type_other' => 'nullable|string|max:255',
            'land_status_id' => 'nullable|integer',
            'ownership_type_id' => 'required|integer',
            'ownership_type_other' => 'nullable|string|max:255',
            'district_id' => 'nullable|integer',
            'upazila_id' => 'nullable|integer',
            'village_id' => 'nullable|integer',
            'mouza' => 'nullable|string|max:255',
            'khatian_number' => 'nullable|string|max:255',
            'dag_number' => 'nullable|string|max:255',
            'la_case_no' => 'nullable|string|max:255',
            'dc_pay_amount' => 'nullable|numeric|min:0',
            'dc_cheque_no' => 'nullable|string|max:50',
            'dc_pay_date' => 'nullable|date',
            'land_area' => 'required|numeric|min:0',
            'land_area_unit_id' => 'required|integer',
            'land_use_type_id' => 'required|integer',
            'land_use_type_other' => 'nullable|string|max:255',
            'remarks' => 'nullable|string|max:1000',
        ]);

        // The wizard sends 0 for an unselected village; treat it as none.
        if (empty($data['village_id'])) {
            $data['village_id'] = null;
        }

        // Auto-derive land_status_id from the parent EP when not provided.
        if (empty($data['land_status_id'])) {
            $data['land_status_id'] = $ep->land_status_id;
        }

        $entity->update($data);

        return ApiResponse::success($entity->fresh(), 'Land updated.');
    }

    public function destroy(AffectedPerson $ep, string $id): JsonResponse
    {
        $entity = Land::where('land_id', $id)
            ->where('affected_person_id', $ep->affected_person_id)
            ->firstOrFail();
        app(AdditionalPaymentRecordService::class)->assertEditable($ep, $entity, 'land');

        $entity->delete();

        return ApiResponse::success(null, 'Land deleted.');
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
                'EP must be in DRAFT status to modify land records.',
                'EP_NOT_EDITABLE',
            );
        }
    }
}
