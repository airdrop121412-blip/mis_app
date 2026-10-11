<?php

namespace App\Http\Controllers\Api\V1\Ep;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AffectedPerson;
use App\Models\Allowance;
use App\Models\VulnerabilityRule;
use App\Services\Audit\StatusHistoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Allowance — vulnerability-based grant for an affected person.
 * status: DRAFT → APPROVED → PAID | REJECTED (from DRAFT or APPROVED).
 */
class AllowanceController extends Controller
{
    private const VALID_TRANSITIONS = [
        'DRAFT' => ['APPROVED', 'REJECTED'],
        'APPROVED' => ['PAID', 'REJECTED'],
    ];

    private const EDITABLE_STATUSES = ['DRAFT'];

    public function __construct(
        private readonly StatusHistoryService $historyService,
    ) {}

    /**
     * GET /api/v1/ep/{ep}/allowance
     */
    public function index(AffectedPerson $ep): JsonResponse
    {
        $allowances = Allowance::where('affected_person_id', $ep->affected_person_id)
            ->with('vulnerabilityRule:rule_code,rule_type,grant_amount')
            ->orderBy('allowance_id')
            ->get();

        return ApiResponse::success($allowances, 'OK');
    }

    /**
     * GET /api/v1/ep/{ep}/allowance/{allowance}
     */
    public function show(AffectedPerson $ep, Allowance $allowance): JsonResponse
    {
        $this->assertBelongsToEp($ep, $allowance);

        return ApiResponse::success(
            $allowance->load('vulnerabilityRule'),
            'OK',
        );
    }

    /**
     * POST /api/v1/ep/{ep}/allowance
     *
     * Create an allowance by specifying a vulnerability_rule_id.
     * Snapshots the rule's grant_amount. One allowance per EP per rule.
     */
    public function store(Request $request, AffectedPerson $ep): JsonResponse
    {
        $data = $request->validate([
            'vulnerability_rule_id' => 'required|integer|exists:vulnerability_rule,vulnerability_rule_id',
            'is_eligible' => 'required|boolean',
            'computed_annual_income' => 'nullable|numeric|min:0',
            'grant_amount' => 'required|numeric|min:0',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $this->assertNoDuplicate($ep, $data['vulnerability_rule_id']);

        $rule = VulnerabilityRule::findOrFail($data['vulnerability_rule_id']);

        if (! $rule->is_active) {
            throw new BusinessRuleException(
                'Vulnerability rule is not active.',
                'RULE_NOT_ACTIVE',
            );
        }

        $allowance = Allowance::create([
            'affected_person_id' => $ep->affected_person_id,
            'vulnerability_rule_id' => $data['vulnerability_rule_id'],
            'is_eligible' => $data['is_eligible'],
            'computed_annual_income' => $data['computed_annual_income'] ?? null,
            'grant_amount' => $data['grant_amount'],
            'status' => 'DRAFT',
            'remarks' => $data['remarks'] ?? null,
        ]);

        $this->historyService->record(
            'ALLOWANCE',
            $allowance->allowance_id,
            null,
            'DRAFT',
            auth()->id(),
        );

        return ApiResponse::created($allowance, 'Allowance created.');
    }

    /**
     * PUT /api/v1/ep/{ep}/allowance/{allowance}
     *
     * Only editable in DRAFT.
     */
    public function update(Request $request, AffectedPerson $ep, Allowance $allowance): JsonResponse
    {
        $this->assertBelongsToEp($ep, $allowance);
        $this->assertEditable($allowance);

        $data = $request->validate([
            'is_eligible' => 'sometimes|required|boolean',
            'computed_annual_income' => 'nullable|numeric|min:0',
            'grant_amount' => 'sometimes|required|numeric|min:0',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $allowance->update($data);

        return ApiResponse::success($allowance->fresh(), 'Allowance updated.');
    }

    /**
     * DELETE /api/v1/ep/{ep}/allowance/{allowance}
     *
     * Only deletable in DRAFT.
     */
    public function destroy(AffectedPerson $ep, Allowance $allowance): JsonResponse
    {
        $this->assertBelongsToEp($ep, $allowance);
        $this->assertEditable($allowance);

        $allowance->delete();

        return ApiResponse::success(null, 'Allowance deleted.');
    }

    /**
     * POST /api/v1/ep/{ep}/allowance/{allowance}/approve
     */
    public function approve(AffectedPerson $ep, Allowance $allowance): JsonResponse
    {
        $this->assertBelongsToEp($ep, $allowance);
        $this->transition($allowance, 'APPROVED');

        return ApiResponse::success($allowance->fresh(), 'Allowance approved.');
    }

    /**
     * POST /api/v1/ep/{ep}/allowance/{allowance}/reject
     */
    public function reject(AffectedPerson $ep, Allowance $allowance): JsonResponse
    {
        $this->assertBelongsToEp($ep, $allowance);
        $this->transition($allowance, 'REJECTED');

        return ApiResponse::success($allowance->fresh(), 'Allowance rejected.');
    }

    /**
     * POST /api/v1/ep/{ep}/allowance/{allowance}/mark-paid
     */
    public function markPaid(AffectedPerson $ep, Allowance $allowance): JsonResponse
    {
        $this->assertBelongsToEp($ep, $allowance);
        $this->transition($allowance, 'PAID');

        return ApiResponse::success($allowance->fresh(), 'Allowance marked as paid.');
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function assertBelongsToEp(AffectedPerson $ep, Allowance $allowance): void
    {
        if ($allowance->affected_person_id !== $ep->affected_person_id) {
            throw new BusinessRuleException(
                'Allowance does not belong to this affected person.',
                'ALLOWANCE_NOT_FOUND',
                404,
            );
        }
    }

    private function assertEditable(Allowance $allowance): void
    {
        if (! in_array($allowance->status, self::EDITABLE_STATUSES)) {
            throw new BusinessRuleException(
                'Allowance cannot be modified in '.$allowance->status.' status.',
                'ALLOWANCE_NOT_EDITABLE',
            );
        }
    }

    private function assertNoDuplicate(AffectedPerson $ep, int $ruleId): void
    {
        if (Allowance::where('affected_person_id', $ep->affected_person_id)
            ->where('vulnerability_rule_id', $ruleId)
            ->exists()
        ) {
            throw new BusinessRuleException(
                'Allowance already exists for this rule.',
                'ALLOWANCE_DUPLICATE',
            );
        }
    }

    private function transition(Allowance $allowance, string $newStatus): void
    {
        $currentStatus = $allowance->status;
        $allowed = self::VALID_TRANSITIONS[$currentStatus] ?? [];

        if (! in_array($newStatus, $allowed)) {
            throw new BusinessRuleException(
                "Cannot transition allowance from {$currentStatus} to {$newStatus}.",
                'INVALID_TRANSITION',
            );
        }

        $allowance->update(['status' => $newStatus]);

        $this->historyService->record(
            'ALLOWANCE',
            $allowance->allowance_id,
            $currentStatus,
            $newStatus,
            auth()->id(),
        );
    }
}
