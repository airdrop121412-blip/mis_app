<?php

namespace App\Http\Controllers\Api\V1\Ep\Nested;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AffectedPerson;
use App\Models\Crop;
use App\Models\CropAssessment;
use App\Models\CropRate;
use App\Models\Land;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Crop Assessment — 1:1 with Crop. Rate snapshot pattern.
 * assessed_loss = cultivated_area × rate_per_unit × (damage_percentage / 100).
 */
class CropAssessmentController extends Controller
{
    /**
     * GET /api/v1/ep/{ep}/land/{land}/crop/{crop}/assessment
     */
    public function show(AffectedPerson $ep, Land $land, Crop $crop): JsonResponse
    {
        $this->assertCropBelongsToLand($ep, $land, $crop);

        $assessment = CropAssessment::where('crop_id', $crop->crop_id)
            ->with('rate:crop_rate_id,rate_per_unit')
            ->firstOrFail();

        return ApiResponse::success($assessment, 'OK');
    }

    /**
     * POST /api/v1/ep/{ep}/land/{land}/crop/{crop}/assessment
     */
    public function store(Request $request, AffectedPerson $ep, Land $land, Crop $crop): JsonResponse
    {
        $this->assertCropBelongsToLand($ep, $land, $crop);
        $this->assertCreate($ep);
        $this->assertNoExisting($crop);

        $data = $request->validate([
            'rate_id' => 'required|integer|exists:crop_rate,crop_rate_id',
            'cultivated_area' => 'required|numeric|min:0',
            'damage_percentage' => 'required|numeric|min:0|max:100',
            'remarks' => 'nullable|string|max:1000',
        ]);

        // Snapshot the rate
        $rate = CropRate::findOrFail($data['rate_id']);
        $data['rate_per_unit'] = $rate->rate_per_unit;
        $data['assessed_loss'] = round(
            $data['cultivated_area'] * $rate->rate_per_unit * ($data['damage_percentage'] / 100),
            2,
        );

        $assessment = CropAssessment::create($data + ['crop_id' => $crop->crop_id]);

        return ApiResponse::created($assessment, 'Crop assessment created.');
    }

    /**
     * PUT /api/v1/ep/{ep}/land/{land}/crop/{crop}/assessment
     */
    public function update(Request $request, AffectedPerson $ep, Land $land, Crop $crop): JsonResponse
    {
        $this->assertCropBelongsToLand($ep, $land, $crop);
        $this->assertDraft($ep);

        $assessment = CropAssessment::where('crop_id', $crop->crop_id)->firstOrFail();

        $data = $request->validate([
            'rate_id' => 'sometimes|required|integer|exists:crop_rate,crop_rate_id',
            'cultivated_area' => 'sometimes|required|numeric|min:0',
            'damage_percentage' => 'sometimes|required|numeric|min:0|max:100',
            'remarks' => 'nullable|string|max:1000',
        ]);

        if (isset($data['rate_id'])) {
            $rate = CropRate::findOrFail($data['rate_id']);
            $data['rate_per_unit'] = $rate->rate_per_unit;
        }

        $assessment->update($data);

        // Recalculate assessed_loss
        $fresh = $assessment->fresh();
        $fresh->update([
            'assessed_loss' => round(
                $fresh->cultivated_area * $fresh->rate_per_unit * ($fresh->damage_percentage / 100),
                2,
            ),
        ]);

        return ApiResponse::success($fresh->fresh(), 'Crop assessment updated.');
    }

    /**
     * DELETE /api/v1/ep/{ep}/land/{land}/crop/{crop}/assessment
     */
    public function destroy(AffectedPerson $ep, Land $land, Crop $crop): JsonResponse
    {
        $this->assertCropBelongsToLand($ep, $land, $crop);
        $this->assertDraft($ep);

        $assessment = CropAssessment::where('crop_id', $crop->crop_id)->firstOrFail();
        $assessment->delete();

        return ApiResponse::success(null, 'Crop assessment deleted.');
    }

    // ── Helpers ──────────────────────────────────────────────────

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
                'EP must be in DRAFT status to modify crop assessments.',
                'EP_NOT_EDITABLE',
            );
        }
    }

    private function assertCropBelongsToLand(AffectedPerson $ep, Land $land, Crop $crop): void
    {
        if ($land->affected_person_id !== $ep->affected_person_id) {
            throw new BusinessRuleException(
                'Land does not belong to this affected person.',
                'LAND_NOT_FOUND',
                404,
            );
        }

        if ($crop->land_id !== $land->land_id) {
            throw new BusinessRuleException(
                'Crop does not belong to this land.',
                'CROP_NOT_FOUND',
                404,
            );
        }
    }

    private function assertNoExisting(Crop $crop): void
    {
        if (CropAssessment::where('crop_id', $crop->crop_id)->exists()) {
            throw new BusinessRuleException(
                'Assessment already exists for this crop.',
                'ASSESSMENT_ALREADY_EXISTS',
            );
        }
    }
}
