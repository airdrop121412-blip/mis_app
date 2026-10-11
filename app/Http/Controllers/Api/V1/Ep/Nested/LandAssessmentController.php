<?php

namespace App\Http\Controllers\Api\V1\Ep\Nested;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AffectedPerson;
use App\Models\Land;
use App\Models\LandAssessment;
use App\Models\LandRate;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Land Assessment — 1:1 with Land. Rate snapshot pattern.
 * assessed_loss = affected_area × rate_per_unit.
 */
class LandAssessmentController extends Controller
{
    /**
     * GET /api/v1/ep/{ep}/land/{land}/assessment
     */
    public function show(AffectedPerson $ep, Land $land): JsonResponse
    {
        $this->assertLandBelongsToEp($ep, $land);

        $assessment = LandAssessment::where('land_id', $land->land_id)
            ->with(['damageType:land_damage_type_id,name', 'unit:unit_id,name', 'rate:land_rate_id,rate_per_unit'])
            ->firstOrFail();

        $assessment->setAttribute('damage_type', $assessment->damageType?->name)
            ->unsetRelation('damageType');

        return ApiResponse::success($assessment, 'OK');
    }

    /**
     * POST /api/v1/ep/{ep}/land/{land}/assessment
     *
     * Exactly one assessment per land.
     */
    public function store(Request $request, AffectedPerson $ep, Land $land): JsonResponse
    {
        $this->assertLandBelongsToEp($ep, $land);
        $this->assertCreate($ep);
        $this->assertNoExisting($land);

        $data = $request->validate([
            'damage_type_id' => 'required|integer|exists:land_damage_type,land_damage_type_id',
            'affected_area' => 'required|numeric|min:0',
            'unit_id' => 'required|integer|exists:unit,unit_id',
            'rate_id' => 'required|integer|exists:land_rate,land_rate_id',
            'remarks' => 'nullable|string|max:1000',
        ]);

        // Snapshot the rate
        $rate = LandRate::findOrFail($data['rate_id']);
        $data['rate_per_unit'] = $rate->rate_per_unit;
        $data['assessed_loss'] = round($data['affected_area'] * $rate->rate_per_unit, 2);

        $assessment = LandAssessment::create($data + ['land_id' => $land->land_id]);

        return ApiResponse::created($assessment, 'Land assessment created.');
    }

    /**
     * PUT /api/v1/ep/{ep}/land/{land}/assessment
     */
    public function update(Request $request, AffectedPerson $ep, Land $land): JsonResponse
    {
        $this->assertLandBelongsToEp($ep, $land);
        $this->assertDraft($ep);

        $assessment = LandAssessment::where('land_id', $land->land_id)->firstOrFail();

        $data = $request->validate([
            'damage_type_id' => 'sometimes|required|integer|exists:land_damage_type,land_damage_type_id',
            'affected_area' => 'sometimes|required|numeric|min:0',
            'unit_id' => 'sometimes|required|integer|exists:unit,unit_id',
            'rate_id' => 'sometimes|required|integer|exists:land_rate,land_rate_id',
            'remarks' => 'nullable|string|max:1000',
        ]);

        // Re-snapshot rate if changed
        if (isset($data['rate_id'])) {
            $rate = LandRate::findOrFail($data['rate_id']);
            $data['rate_per_unit'] = $rate->rate_per_unit;
        }

        $assessment->update($data);

        // Recalculate assessed_loss
        $fresh = $assessment->fresh();
        $fresh->update([
            'assessed_loss' => round($fresh->affected_area * $fresh->rate_per_unit, 2),
        ]);

        return ApiResponse::success($fresh->fresh(), 'Land assessment updated.');
    }

    /**
     * DELETE /api/v1/ep/{ep}/land/{land}/assessment
     */
    public function destroy(AffectedPerson $ep, Land $land): JsonResponse
    {
        $this->assertLandBelongsToEp($ep, $land);
        $this->assertDraft($ep);

        $assessment = LandAssessment::where('land_id', $land->land_id)->firstOrFail();
        $assessment->delete();

        return ApiResponse::success(null, 'Land assessment deleted.');
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
                'EP must be in DRAFT status to modify land assessments.',
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

    private function assertNoExisting(Land $land): void
    {
        if (LandAssessment::where('land_id', $land->land_id)->exists()) {
            throw new BusinessRuleException(
                'Assessment already exists for this land.',
                'ASSESSMENT_ALREADY_EXISTS',
            );
        }
    }
}
