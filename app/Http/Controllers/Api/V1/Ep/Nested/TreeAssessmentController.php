<?php

namespace App\Http\Controllers\Api\V1\Ep\Nested;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AffectedPerson;
use App\Models\Tree;
use App\Models\TreeAssessment;
use App\Models\TreeRate;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tree Assessment — 1:1 with Tree. Rate snapshot pattern.
 * assessed_loss = quantity × rate_per_tree.
 */
class TreeAssessmentController extends Controller
{
    /**
     * GET /api/v1/ep/{ep}/tree/{tree}/assessment
     */
    public function show(AffectedPerson $ep, Tree $tree): JsonResponse
    {
        $this->assertBelongsToEp($ep, $tree);

        $assessment = TreeAssessment::where('tree_id', $tree->tree_id)
            ->with('rate:tree_rate_id,rate_per_tree')
            ->firstOrFail();

        return ApiResponse::success($assessment, 'OK');
    }

    /**
     * POST /api/v1/ep/{ep}/tree/{tree}/assessment
     */
    public function store(Request $request, AffectedPerson $ep, Tree $tree): JsonResponse
    {
        $this->assertBelongsToEp($ep, $tree);
        $this->assertCreate($ep);
        $this->assertNoExisting($tree);

        $data = $request->validate([
            'rate_id' => 'required|integer|exists:tree_rate,tree_rate_id',
            'quantity' => 'required|integer|min:1',
            'remarks' => 'nullable|string|max:1000',
        ]);

        // Snapshot the rate
        $rate = TreeRate::findOrFail($data['rate_id']);
        $data['rate_per_tree'] = $rate->rate_per_tree;
        $data['assessed_loss'] = round($data['quantity'] * $rate->rate_per_tree, 2);

        $assessment = TreeAssessment::create($data + ['tree_id' => $tree->tree_id]);

        return ApiResponse::created($assessment, 'Tree assessment created.');
    }

    /**
     * PUT /api/v1/ep/{ep}/tree/{tree}/assessment
     */
    public function update(Request $request, AffectedPerson $ep, Tree $tree): JsonResponse
    {
        $this->assertBelongsToEp($ep, $tree);
        $this->assertDraft($ep);

        $assessment = TreeAssessment::where('tree_id', $tree->tree_id)->firstOrFail();

        $data = $request->validate([
            'rate_id' => 'sometimes|required|integer|exists:tree_rate,tree_rate_id',
            'quantity' => 'sometimes|required|integer|min:1',
            'remarks' => 'nullable|string|max:1000',
        ]);

        if (isset($data['rate_id'])) {
            $rate = TreeRate::findOrFail($data['rate_id']);
            $data['rate_per_tree'] = $rate->rate_per_tree;
        }

        $assessment->update($data);

        // Recalculate assessed_loss
        $fresh = $assessment->fresh();
        $fresh->update([
            'assessed_loss' => round($fresh->quantity * $fresh->rate_per_tree, 2),
        ]);

        return ApiResponse::success($fresh->fresh(), 'Tree assessment updated.');
    }

    /**
     * DELETE /api/v1/ep/{ep}/tree/{tree}/assessment
     */
    public function destroy(AffectedPerson $ep, Tree $tree): JsonResponse
    {
        $this->assertBelongsToEp($ep, $tree);
        $this->assertDraft($ep);

        $assessment = TreeAssessment::where('tree_id', $tree->tree_id)->firstOrFail();
        $assessment->delete();

        return ApiResponse::success(null, 'Tree assessment deleted.');
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
                'EP must be in DRAFT status to modify tree assessments.',
                'EP_NOT_EDITABLE',
            );
        }
    }

    private function assertBelongsToEp(AffectedPerson $ep, Tree $tree): void
    {
        if ($tree->affected_person_id !== $ep->affected_person_id) {
            throw new BusinessRuleException(
                'Tree does not belong to this affected person.',
                'TREE_NOT_FOUND',
                404,
            );
        }
    }

    private function assertNoExisting(Tree $tree): void
    {
        if (TreeAssessment::where('tree_id', $tree->tree_id)->exists()) {
            throw new BusinessRuleException(
                'Assessment already exists for this tree.',
                'ASSESSMENT_ALREADY_EXISTS',
            );
        }
    }
}
