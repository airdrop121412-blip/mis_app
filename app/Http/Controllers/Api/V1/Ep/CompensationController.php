<?php

namespace App\Http\Controllers\Api\V1\Ep;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AffectedPerson;
use App\Models\Compensation;
use App\Models\Ec;
use App\Models\EcLineItem;
use App\Models\VoucherCompensation;
use App\Services\Audit\StatusHistoryService;
use App\Services\Ep\AdditionalPaymentRecordService;
use App\Support\ApiResponse;
use App\Support\CompensationCategories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Compensation — approved amount per EC per category.
 * status: DRAFT → APPROVED → PAID | CANCELLED (from DRAFT or APPROVED).
 *
 * Each compensation maps 1:1 to an ec_line_item (by ec_id + category).
 * assessed_amount is snapshotted from the line item.
 */
class CompensationController extends Controller
{
    private const VALID_TRANSITIONS = [
        'DRAFT' => ['APPROVED', 'CANCELLED'],
        'APPROVED' => ['PAID', 'CANCELLED'],
    ];

    private const EDITABLE_STATUSES = ['DRAFT'];

    public function __construct(
        private readonly StatusHistoryService $historyService,
        private readonly AdditionalPaymentRecordService $additionalPaymentRecords,
    ) {}

    /**
     * GET /api/v1/ep/{ep}/compensation
     *
     * List all compensations for this EP's EC.
     * Includes backend-computed paid_amount (sum of voucher_compensation.amount)
     * and remaining_amount (approved_amount - paid_amount) so the frontend never
     * fabricates financial values.
     */
    public function index(AffectedPerson $ep): JsonResponse
    {
        $ec = $this->getEc($ep);

        $items = Compensation::with('voucherCompensations')
            ->where('ec_id', $ec->ec_id)
            ->orderBy('category')
            ->get();
        $items = $this->additionalPaymentRecords->decorate($items, 'compensation')
            ->map(function (Compensation $c) {
                $paid = (float) $c->voucherCompensations->sum('amount');
                $approved = (float) $c->approved_amount;
                $data = $c->toArray();
                $data['paid_amount'] = number_format($paid, 2, '.', '');
                $data['remaining_amount'] = number_format(max($approved - $paid, 0), 2, '.', '');
                unset($data['voucher_compensations']);

                return $data;
            });

        return ApiResponse::success($items, 'OK');
    }

    /**
     * GET /api/v1/ep/{ep}/compensation/{compensation}
     */
    public function show(AffectedPerson $ep, Compensation $compensation): JsonResponse
    {
        $this->assertBelongsToEp($ep, $compensation);

        return ApiResponse::success($compensation, 'OK');
    }

    /**
     * POST /api/v1/ep/{ep}/compensation
     *
     * Create compensation lines from EC line items.
     * If category is specified, create one line; otherwise create all.
     * assessed_amount is snapshotted from ec_line_item.
     */
    public function store(Request $request, AffectedPerson $ep): JsonResponse
    {
        $ec = $this->getEc($ep);
        $this->assertEcApproved($ec);

        $data = $request->validate([
            'category' => 'required|string|in:'.CompensationCategories::ruleList(),
            'approved_amount' => 'required|numeric|min:0',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $lineItem = EcLineItem::where('ec_id', $ec->ec_id)
            ->where('category', $data['category'])
            ->first();

        if (! $lineItem) {
            throw new BusinessRuleException(
                'No EC line item found for category: '.$data['category'],
                'LINE_ITEM_NOT_FOUND',
            );
        }

        $this->assertNoDuplicate($ec, $data['category']);

        $compensation = Compensation::create([
            'ec_id' => $ec->ec_id,
            'category' => $data['category'],
            'assessed_amount' => $lineItem->assessed_amount,
            'approved_amount' => $data['approved_amount'],
            'status' => 'DRAFT',
            'remarks' => $data['remarks'] ?? null,
        ]);
        $this->historyService->record(
            'COMPENSATION',
            $compensation->compensation_id,
            null,
            'DRAFT',
            auth()->id(),
        );

        return ApiResponse::created($compensation, 'Compensation created.');
    }

    /**
     * Add a new loss category after the original EP has been paid.
     * Existing compensation rows remain immutable.
     */
    public function additional(Request $request, AffectedPerson $ep): JsonResponse
    {
        if ($ep->status !== 'PAID') {
            throw new BusinessRuleException('Only PAID EPs can receive additional payment losses.', 'EP_NOT_PAID');
        }

        $ec = $this->getEc($ep);
        $data = $request->validate([
            'category' => 'required|string|in:'.CompensationCategories::ruleList(),
            'approved_amount' => 'required|numeric|min:0.01',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $alreadyAddedThisCycle = $ec->compensations()
            ->where('category', $data['category'])
            ->where('payment_cycle', 2)
            ->exists();
        if ($alreadyAddedThisCycle) {
            throw new BusinessRuleException(
                'This loss category was already added for the additional payment.',
                'ADDITIONAL_CATEGORY_EXISTS',
            );
        }

        $line = $ec->lineItems()->where('category', $data['category'])->first();
        $compensation = Compensation::create([
            'ec_id' => $ec->ec_id,
            'category' => $data['category'],
            'assessed_amount' => $line?->assessed_amount ?? $data['approved_amount'],
            'approved_amount' => $data['approved_amount'],
            'status' => 'APPROVED',
            'payment_cycle' => 2,
            'remarks' => $data['remarks'] ?? 'Additional loss after previous payment.',
        ]);
        $this->additionalPaymentRecords->register($compensation, $ep);

        $ec->update([
            'total_compensation' => $ec->compensations()->sum('approved_amount'),
        ]);

        return ApiResponse::created($compensation, 'Additional compensation added.');
    }

    /**
     * PUT /api/v1/ep/{ep}/compensation/{compensation}
     *
     * DRAFT records are fully editable. APPROVED records may be updated only through the additional-payment guard.
     */
    public function update(Request $request, AffectedPerson $ep, Compensation $compensation): JsonResponse
    {
        $this->assertBelongsToEp($ep, $compensation);
        $this->assertEditable($ep, $compensation);

        $data = $request->validate([
            'approved_amount' => 'sometimes|required|numeric|min:0',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $compensation->update($data);

        return ApiResponse::success($compensation->fresh(), 'Compensation updated.');
    }

    /**
     * DELETE /api/v1/ep/{ep}/compensation/{compensation}
     *
     * Only deletable in DRAFT.
     */
    public function destroy(AffectedPerson $ep, Compensation $compensation): JsonResponse
    {
        $this->assertBelongsToEp($ep, $compensation);
        $this->assertEditable($ep, $compensation);

        $compensation->delete();

        return ApiResponse::success(null, 'Compensation deleted.');
    }

    /**
     * POST /api/v1/ep/{ep}/compensation/{compensation}/approve
     */
    public function approve(AffectedPerson $ep, Compensation $compensation): JsonResponse
    {
        $this->assertBelongsToEp($ep, $compensation);
        $this->transition($compensation, 'APPROVED');

        return ApiResponse::success($compensation->fresh(), 'Compensation approved.');
    }

    /**
     * POST /api/v1/ep/{ep}/compensation/{compensation}/cancel
     */
    public function cancel(AffectedPerson $ep, Compensation $compensation): JsonResponse
    {
        $this->assertBelongsToEp($ep, $compensation);
        $this->transition($compensation, 'CANCELLED');

        return ApiResponse::success($compensation->fresh(), 'Compensation cancelled.');
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function getEc(AffectedPerson $ep): Ec
    {
        return Ec::where('affected_person_id', $ep->affected_person_id)
            ->firstOrFail();
    }

    private function assertEcApproved(Ec $ec): void
    {
        if ($ec->status !== 'APPROVED') {
            throw new BusinessRuleException(
                'EC must be APPROVED to create compensation.',
                'EC_NOT_APPROVED',
            );
        }
    }

    private function assertBelongsToEp(AffectedPerson $ep, Compensation $compensation): void
    {
        $ec = $this->getEc($ep);

        if ($compensation->ec_id !== $ec->ec_id) {
            throw new BusinessRuleException(
                'Compensation does not belong to this EP.',
                'COMPENSATION_NOT_FOUND',
                404,
            );
        }
    }

    private function assertEditable(AffectedPerson $ep, Compensation $compensation): void
    {
        if ($compensation->status === 'DRAFT' && $ep->canBeEditedBy(request()->user())) {
            return;
        }

        if ($compensation->status === 'APPROVED') {
            $this->additionalPaymentRecords->assertEditable($ep, $compensation, 'compensation');

            return;
        }

        if (! in_array($compensation->status, self::EDITABLE_STATUSES, true)) {
            throw new BusinessRuleException(
                'Compensation cannot be modified in '.$compensation->status.' status.',
                'COMPENSATION_NOT_EDITABLE',
            );
        }
    }

    private function assertNoDuplicate(Ec $ec, string $category): void
    {
        if (Compensation::where('ec_id', $ec->ec_id)
            ->where('category', $category)
            ->exists()
        ) {
            throw new BusinessRuleException(
                'Compensation already exists for category: '.$category,
                'COMPENSATION_DUPLICATE',
            );
        }
    }

    private function transition(Compensation $compensation, string $newStatus): void
    {
        $currentStatus = $compensation->status;
        $allowed = self::VALID_TRANSITIONS[$currentStatus] ?? [];

        if (! in_array($newStatus, $allowed)) {
            throw new BusinessRuleException(
                "Cannot transition compensation from {$currentStatus} to {$newStatus}.",
                'INVALID_TRANSITION',
            );
        }

        // Cancelled compensation must not be in any voucher
        if ($newStatus === 'CANCELLED') {
            $this->assertNotInVoucher($compensation);
        }

        $compensation->update(['status' => $newStatus]);

        $this->historyService->record(
            'COMPENSATION',
            $compensation->compensation_id,
            $currentStatus,
            $newStatus,
            auth()->id(),
        );
    }

    private function assertNotInVoucher(Compensation $compensation): void
    {
        if (VoucherCompensation::where('compensation_id', $compensation->compensation_id)->exists()) {
            throw new BusinessRuleException(
                'Cannot cancel compensation that is attached to a voucher.',
                'COMPENSATION_IN_VOUCHER',
            );
        }
    }
}
