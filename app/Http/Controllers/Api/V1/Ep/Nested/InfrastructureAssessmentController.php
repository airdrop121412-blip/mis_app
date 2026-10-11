<?php

namespace App\Http\Controllers\Api\V1\Ep\Nested;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AffectedPerson;
use App\Models\Infrastructure;
use App\Models\InfrastructureAssessment;
use App\Models\InfrastructureRate;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Infrastructure Assessment — 1:1 with Infrastructure. Rate snapshot pattern.
 * assessed_loss = area_sqft × rate_per_sqft × (damage_percentage / 100).
 */
class InfrastructureAssessmentController extends Controller
{
    /**
     * GET /api/v1/ep/{ep}/infrastructure/{infrastructure}/assessment
     */
    public function show(AffectedPerson $ep, Infrastructure $infrastructure): JsonResponse
    {
        $this->assertBelongsToEp($ep, $infrastructure);

        $assessment = InfrastructureAssessment::where('infrastructure_id', $infrastructure->infrastructure_id)
            ->with('rate:infrastructure_rate_id,rate_per_sqft')
            ->firstOrFail();

        return ApiResponse::success($assessment, 'OK');
    }

    /**
     * POST /api/v1/ep/{ep}/infrastructure/{infrastructure}/assessment
     */
    public function store(Request $request, AffectedPerson $ep, Infrastructure $infrastructure): JsonResponse
    {
        $this->assertBelongsToEp($ep, $infrastructure);
        $this->assertCreate($ep);
        $this->assertNoExisting($infrastructure);

        $data = $request->validate([
            'rate_id' => 'required|integer|exists:infrastructure_rate,infrastructure_rate_id',
            'area_sqft' => 'required|numeric|min:0',
            'damage_percentage' => 'required|numeric|min:0|max:100',
            'remarks' => 'nullable|string|max:1000',
        ]);

        // Snapshot the rate
        $rate = InfrastructureRate::findOrFail($data['rate_id']);
        $data['rate_per_sqft'] = $rate->rate_per_sqft;
        $data['assessed_loss'] = round(
            $data['area_sqft'] * $rate->rate_per_sqft * ($data['damage_percentage'] / 100),
            2,
        );

        $assessment = InfrastructureAssessment::create(
            $data + ['infrastructure_id' => $infrastructure->infrastructure_id],
        );

        return ApiResponse::created($assessment, 'Infrastructure assessment created.');
    }

    /**
     * PUT /api/v1/ep/{ep}/infrastructure/{infrastructure}/assessment
     */
    public function update(Request $request, AffectedPerson $ep, Infrastructure $infrastructure): JsonResponse
    {
        $this->assertBelongsToEp($ep, $infrastructure);
        $this->assertDraft($ep);

        $assessment = InfrastructureAssessment::where('infrastructure_id', $infrastructure->infrastructure_id)
            ->firstOrFail();

        $data = $request->validate([
            'rate_id' => 'sometimes|required|integer|exists:infrastructure_rate,infrastructure_rate_id',
            'area_sqft' => 'sometimes|required|numeric|min:0',
            'damage_percentage' => 'sometimes|required|numeric|min:0|max:100',
            'remarks' => 'nullable|string|max:1000',
        ]);

        if (isset($data['rate_id'])) {
            $rate = InfrastructureRate::findOrFail($data['rate_id']);
            $data['rate_per_sqft'] = $rate->rate_per_sqft;
        }

        $assessment->update($data);

        // Recalculate assessed_loss
        $fresh = $assessment->fresh();
        $fresh->update([
            'assessed_loss' => round(
                $fresh->area_sqft * $fresh->rate_per_sqft * ($fresh->damage_percentage / 100),
                2,
            ),
        ]);

        return ApiResponse::success($fresh->fresh(), 'Infrastructure assessment updated.');
    }

    /**
     * DELETE /api/v1/ep/{ep}/infrastructure/{infrastructure}/assessment
     */
    public function destroy(AffectedPerson $ep, Infrastructure $infrastructure): JsonResponse
    {
        $this->assertBelongsToEp($ep, $infrastructure);
        $this->assertDraft($ep);

        $assessment = InfrastructureAssessment::where('infrastructure_id', $infrastructure->infrastructure_id)
            ->firstOrFail();
        $assessment->delete();

        return ApiResponse::success(null, 'Infrastructure assessment deleted.');
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
                'EP must be in DRAFT status to modify infrastructure assessments.',
                'EP_NOT_EDITABLE',
            );
        }
    }

    private function assertBelongsToEp(AffectedPerson $ep, Infrastructure $infrastructure): void
    {
        if ($infrastructure->affected_person_id !== $ep->affected_person_id) {
            throw new BusinessRuleException(
                'Infrastructure does not belong to this affected person.',
                'INFRASTRUCTURE_NOT_FOUND',
                404,
            );
        }
    }

    private function assertNoExisting(Infrastructure $infrastructure): void
    {
        if (InfrastructureAssessment::where('infrastructure_id', $infrastructure->infrastructure_id)->exists()) {
            throw new BusinessRuleException(
                'Assessment already exists for this infrastructure.',
                'ASSESSMENT_ALREADY_EXISTS',
            );
        }
    }
}
