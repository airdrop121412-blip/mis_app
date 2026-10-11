<?php

namespace App\Http\Controllers\Api\V1\Ep\Nested;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AffectedPerson;
use App\Models\Worker;
use App\Models\WorkerAssessment;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Worker Assessment — manual amount (no rate master). 1:1 with Worker.
 */
class WorkerAssessmentController extends Controller
{
    /**
     * GET /api/v1/ep/{ep}/worker/{worker}/assessment
     */
    public function show(AffectedPerson $ep, Worker $worker): JsonResponse
    {
        $this->assertBelongsToEp($ep, $worker);

        $assessment = WorkerAssessment::where('worker_id', $worker->worker_id)
            ->firstOrFail();

        return ApiResponse::success($assessment, 'OK');
    }

    /**
     * POST /api/v1/ep/{ep}/worker/{worker}/assessment
     */
    public function store(Request $request, AffectedPerson $ep, Worker $worker): JsonResponse
    {
        $this->assertBelongsToEp($ep, $worker);
        $this->assertCreate($ep);
        $this->assertNoExisting($worker);

        $data = $request->validate([
            'loss_amount' => 'required|numeric|min:0',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $assessment = WorkerAssessment::create($data + ['worker_id' => $worker->worker_id]);

        return ApiResponse::created($assessment, 'Worker assessment created.');
    }

    /**
     * PUT /api/v1/ep/{ep}/worker/{worker}/assessment
     */
    public function update(Request $request, AffectedPerson $ep, Worker $worker): JsonResponse
    {
        $this->assertBelongsToEp($ep, $worker);
        $this->assertDraft($ep);

        $assessment = WorkerAssessment::where('worker_id', $worker->worker_id)->firstOrFail();

        $data = $request->validate([
            'loss_amount' => 'required|numeric|min:0',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $assessment->update($data);

        return ApiResponse::success($assessment->fresh(), 'Worker assessment updated.');
    }

    /**
     * DELETE /api/v1/ep/{ep}/worker/{worker}/assessment
     */
    public function destroy(AffectedPerson $ep, Worker $worker): JsonResponse
    {
        $this->assertBelongsToEp($ep, $worker);
        $this->assertDraft($ep);

        $assessment = WorkerAssessment::where('worker_id', $worker->worker_id)->firstOrFail();
        $assessment->delete();

        return ApiResponse::success(null, 'Worker assessment deleted.');
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
                'EP must be in DRAFT status to modify worker assessments.',
                'EP_NOT_EDITABLE',
            );
        }
    }

    private function assertBelongsToEp(AffectedPerson $ep, Worker $worker): void
    {
        if ($worker->affected_person_id !== $ep->affected_person_id) {
            throw new BusinessRuleException(
                'Worker does not belong to this affected person.',
                'WORKER_NOT_FOUND',
                404,
            );
        }
    }

    private function assertNoExisting(Worker $worker): void
    {
        if (WorkerAssessment::where('worker_id', $worker->worker_id)->exists()) {
            throw new BusinessRuleException(
                'Assessment already exists for this worker.',
                'ASSESSMENT_ALREADY_EXISTS',
            );
        }
    }
}
