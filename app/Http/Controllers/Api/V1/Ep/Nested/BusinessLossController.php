<?php

namespace App\Http\Controllers\Api\V1\Ep\Nested;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AffectedPerson;
use App\Models\Business;
use App\Models\BusinessLoss;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Business Loss — manual amount (no rate master). 1:1 with Business.
 */
class BusinessLossController extends Controller
{
    /**
     * GET /api/v1/ep/{ep}/business/{business}/loss
     */
    public function show(AffectedPerson $ep, Business $business): JsonResponse
    {
        $this->assertBelongsToEp($ep, $business);

        $loss = BusinessLoss::where('business_id', $business->business_id)
            ->firstOrFail();

        return ApiResponse::success($loss, 'OK');
    }

    /**
     * POST /api/v1/ep/{ep}/business/{business}/loss
     */
    public function store(Request $request, AffectedPerson $ep, Business $business): JsonResponse
    {
        $this->assertBelongsToEp($ep, $business);
        $this->assertCreate($ep);
        $this->assertNoExisting($business);

        $data = $request->validate([
            'loss_amount' => 'required|numeric|min:0',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $loss = BusinessLoss::create($data + ['business_id' => $business->business_id]);

        return ApiResponse::created($loss, 'Business loss recorded.');
    }

    /**
     * PUT /api/v1/ep/{ep}/business/{business}/loss
     */
    public function update(Request $request, AffectedPerson $ep, Business $business): JsonResponse
    {
        $this->assertBelongsToEp($ep, $business);
        $this->assertDraft($ep);

        $loss = BusinessLoss::where('business_id', $business->business_id)->firstOrFail();

        $data = $request->validate([
            'loss_amount' => 'required|numeric|min:0',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $loss->update($data);

        return ApiResponse::success($loss->fresh(), 'Business loss updated.');
    }

    /**
     * DELETE /api/v1/ep/{ep}/business/{business}/loss
     */
    public function destroy(AffectedPerson $ep, Business $business): JsonResponse
    {
        $this->assertBelongsToEp($ep, $business);
        $this->assertDraft($ep);

        $loss = BusinessLoss::where('business_id', $business->business_id)->firstOrFail();
        $loss->delete();

        return ApiResponse::success(null, 'Business loss deleted.');
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
                'EP must be in DRAFT status to modify business losses.',
                'EP_NOT_EDITABLE',
            );
        }
    }

    private function assertBelongsToEp(AffectedPerson $ep, Business $business): void
    {
        if ($business->affected_person_id !== $ep->affected_person_id) {
            throw new BusinessRuleException(
                'Business does not belong to this affected person.',
                'BUSINESS_NOT_FOUND',
                404,
            );
        }
    }

    private function assertNoExisting(Business $business): void
    {
        if (BusinessLoss::where('business_id', $business->business_id)->exists()) {
            throw new BusinessRuleException(
                'Business loss already recorded for this business.',
                'BUSINESS_LOSS_ALREADY_EXISTS',
            );
        }
    }
}
