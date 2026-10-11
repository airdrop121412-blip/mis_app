<?php

namespace App\Http\Controllers\Api\V1\Ep\Nested;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AffectedPerson;
use App\Models\Crop;
use App\Models\Land;
use App\Services\Ep\AdditionalPaymentRecordService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CropController extends Controller
{
    public function index(Request $request, AffectedPerson $ep, Land $land): JsonResponse
    {
        $this->assertLandBelongsToEp($ep, $land);

        $query = Crop::where('land_id', $land->land_id);

        $perPage = min((int) $request->input('per_page', 25), 100);
        $paginator = $query->with([
            'cropType:crop_type_id,name',
            'cropSeason:crop_season_id,name',
            'areaUnit:unit_id,name',
        ])->orderByDesc('crop_id')->paginate($perPage);

        $paginator->getCollection()->transform(function (Crop $crop): Crop {
            return $crop->setAttribute('crop_type', $crop->cropType?->name)
                ->setAttribute('crop_season', $crop->cropSeason?->name)
                ->setAttribute('area_unit', $crop->areaUnit?->name)
                ->unsetRelation('cropType')
                ->unsetRelation('cropSeason')
                ->unsetRelation('areaUnit');
        });
        $paginator->setCollection(app(AdditionalPaymentRecordService::class)
            ->decorate($paginator->getCollection(), 'crop'));

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

    public function store(Request $request, AffectedPerson $ep, Land $land): JsonResponse
    {
        $this->assertCreate($ep);
        $this->assertLandBelongsToEp($ep, $land);

        $data = $request->validate([
            'crop_type_id' => 'required|integer',
            'crop_type_other' => 'nullable|string|max:255',
            'crop_season_id' => 'required|integer',
            'cultivated_area' => 'required|numeric|min:0',
            'area_unit_id' => 'required|integer',
            'damage_percentage' => 'required|numeric|min:0|max:100',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $data['land_id'] = $land->land_id;

        $entity = Crop::create($data);
        app(AdditionalPaymentRecordService::class)->register($entity, $ep);

        return ApiResponse::created($entity, 'Crop created.');
    }

    public function show(AffectedPerson $ep, Land $land, string $id): JsonResponse
    {
        $this->assertLandBelongsToEp($ep, $land);

        $entity = Crop::where('crop_id', $id)
            ->where('land_id', $land->land_id)
            ->firstOrFail();

        return ApiResponse::success($entity, 'OK');
    }

    public function update(Request $request, AffectedPerson $ep, Land $land, string $id): JsonResponse
    {
        $this->assertLandBelongsToEp($ep, $land);

        $entity = Crop::where('crop_id', $id)
            ->where('land_id', $land->land_id)
            ->firstOrFail();
        app(AdditionalPaymentRecordService::class)->assertEditable($ep, $entity, 'crop');

        $data = $request->validate([
            'crop_type_id' => 'required|integer',
            'crop_type_other' => 'nullable|string|max:255',
            'crop_season_id' => 'required|integer',
            'cultivated_area' => 'required|numeric|min:0',
            'area_unit_id' => 'required|integer',
            'damage_percentage' => 'required|numeric|min:0|max:100',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $entity->update($data);

        return ApiResponse::success($entity->fresh(), 'Crop updated.');
    }

    public function destroy(AffectedPerson $ep, Land $land, string $id): JsonResponse
    {
        $this->assertLandBelongsToEp($ep, $land);

        $entity = Crop::where('crop_id', $id)
            ->where('land_id', $land->land_id)
            ->firstOrFail();
        app(AdditionalPaymentRecordService::class)->assertEditable($ep, $entity, 'crop');

        $entity->delete();

        return ApiResponse::success(null, 'Crop deleted.');
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
                'EP must be in DRAFT status to modify crop records.',
                'EP_NOT_EDITABLE',
            );
        }
    }

    private function assertLandBelongsToEp(AffectedPerson $ep, Land $land): void
    {
        if ($land->affected_person_id !== $ep->affected_person_id) {
            throw new BusinessRuleException(
                'Land does not belong to this affected person.',
                'LAND_NOT_FOUND',
                404,
            );
        }
    }
}
