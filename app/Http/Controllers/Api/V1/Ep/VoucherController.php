<?php

namespace App\Http\Controllers\Api\V1\Ep;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AdditionalPaymentRecord;
use App\Models\AffectedPerson;
use App\Models\Compensation;
use App\Models\Ec;
use App\Models\Epicad;
use App\Models\Payment;
use App\Models\Voucher;
use App\Models\VoucherCompensation;
use App\Services\Audit\StatusHistoryService;
use App\Services\Ep\AdditionalPaymentRecordService;
use App\Services\Ep\EcCalculationService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Voucher — payment batch per EC.
 *
 * Workflow:
 *   1. Create (prepared) → payment_status = PENDING
 *   2. Attach compensation lines
 *   3. Verify → verified_by set
 *   4. Approve → approved_by set
 *   5. Payments change payment_status: PENDING → PARTIAL → PAID
 *
 * Cancellation is allowed from PENDING only (no partial payments yet).
 */
class VoucherController extends Controller
{
    private const VALID_TRANSITIONS = [
        'PENDING' => ['CANCELLED'],
    ];

    private const EDITABLE_STATUSES = ['PENDING'];

    public function __construct(
        private readonly StatusHistoryService $historyService,
        private readonly EcCalculationService $ecCalculation,
        private readonly AdditionalPaymentRecordService $additionalPaymentRecords,
    ) {}

    /**
     * GET /api/v1/ep/{ep}/voucher
     *
     * Each voucher carries a persistent `is_additional` flag derived from its
     * attached compensation lines (payment_cycle 2) — never from the EP's
     * PAID status alone — so the UI can show an "Additional Payment" remark
     * per voucher.
     */
    public function index(AffectedPerson $ep): JsonResponse
    {
        $ec = Ec::where('affected_person_id', $ep->affected_person_id)->first();
        if (! $ec) {
            return ApiResponse::success([], 'OK');
        }

        $vouchers = Voucher::where('ec_id', $ec->ec_id)
            ->orderByDesc('voucher_id')
            ->get()
            ->each(function (Voucher $voucher): void {
                $voucher->setAttribute('is_additional', $this->isAdditionalVoucher($voucher));
            });

        return ApiResponse::success($vouchers, 'OK');
    }

    /**
     * GET /api/v1/ep/additional-payment-pending
     *
     * Worklist of PAID EPs that already have new loss data waiting for a 2nd
     * voucher: un-vouchered second-payment records (land, crop, structure,
     * tree, business) and/or un-vouchered cycle-2 compensations. Each entry
     * carries a remark explaining why it is waiting.
     */
    public function additionalPending(Request $request): JsonResponse
    {
        $query = AffectedPerson::query()->where('status', 'PAID');

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->integer('project_id'));
        }

        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($builder) use ($q): void {
                $builder->where('hh_id', 'like', "%{$q}%")
                    ->orWhere('ep_id', 'like', "%{$q}%")
                    ->orWhere('name', 'like', "%{$q}%")
                    ->orWhere('nid_number', 'like', "%{$q}%");
            });
        }

        $items = [];
        foreach ($query->orderBy('affected_person_id')->cursor() as $ep) {
            $ec = Ec::where('affected_person_id', $ep->affected_person_id)->first();
            if (! $ec) {
                continue;
            }

            $newRecordGroups = AdditionalPaymentRecord::where('affected_person_id', $ep->affected_person_id)
                ->whereNull('voucher_id')
                ->where('record_type', '!=', 'compensation')
                ->get(['record_type', 'record_id']);

            $unvoucheredCompensations = Compensation::where('ec_id', $ec->ec_id)
                ->where('payment_cycle', 2)
                ->where('status', 'APPROVED')
                ->whereNotExists(function ($exists): void {
                    $exists->select(DB::raw(1))
                        ->from('voucher_compensation')
                        ->whereColumn('voucher_compensation.compensation_id', 'compensation.compensation_id');
                })
                ->get(['compensation_id', 'category', 'approved_amount']);

            $autoAmounts = empty($newRecordGroups) && $unvoucheredCompensations->isEmpty()
                ? []
                : $this->ecCalculation->calculateAdditional($ep);
            $autoTotal = array_sum(array_filter($autoAmounts, fn ($amount) => $amount > 0));
            $manualTotal = (float) $unvoucheredCompensations->sum('approved_amount');

            if ($newRecordGroups->isEmpty() && $unvoucheredCompensations->isEmpty()) {
                continue;
            }

            $activeVoucher = Voucher::where('ec_id', $ec->ec_id)
                ->whereNotIn('payment_status', ['CANCELLED', 'PAID'])
                ->orderByDesc('voucher_id')
                ->first(['voucher_id', 'voucher_number', 'payment_status']);

            $remarks = [];
            if (! $newRecordGroups->isEmpty()) {
                $labels = $newRecordGroups->groupBy('record_type')
                    ->map(fn ($rows, $type) => $rows->count().' '.str_replace('_', ' ', (string) $type))
                    ->values()->all();
                $remarks[] = 'Additional Payment — new '.implode(', ', $labels).' entered after previous payment.';
            }
            if (! $unvoucheredCompensations->isEmpty()) {
                $cats = $unvoucheredCompensations->pluck('category')->unique()->values()->all();
                $remarks[] = 'Additional Payment — '.implode(', ', $cats).' compensation ready for voucher.';
            }
            if ($activeVoucher) {
                $remarks[] = 'Waiting: finish voucher '.$activeVoucher->voucher_number.' ('.$activeVoucher->payment_status.') first.';
            }

            $items[] = [
                'affected_person_id' => $ep->affected_person_id,
                'ep_id' => $ep->ep_id,
                'name' => $ep->name,
                'status' => $ep->status,
                'estimated_additional_amount' => number_format(max($manualTotal, $autoTotal), 2, '.', ''),
                'has_active_voucher' => $activeVoucher !== null,
                'active_voucher_number' => $activeVoucher?->voucher_number,
                'remark' => implode(' ', $remarks),
            ];
        }

        return ApiResponse::success(array_values($items), 'OK');
    }

    /**
     * A voucher is an additional-payment voucher only when it carries a
     * compensation registered as second-payment (payment_cycle 2).
     */
    private function isAdditionalVoucher(Voucher $voucher): bool
    {
        $compensationIds = VoucherCompensation::where('voucher_id', $voucher->voucher_id)
            ->pluck('compensation_id');

        if ($compensationIds->isEmpty()) {
            return false;
        }

        return AdditionalPaymentRecord::where('record_type', 'compensation')
            ->whereIn('record_id', $compensationIds)
            ->exists();
    }

    /**
     * GET /api/v1/ep/{ep}/voucher/{voucher}
     */
    public function show(AffectedPerson $ep, Voucher $voucher): JsonResponse
    {
        $this->assertBelongsToEp($ep, $voucher);

        $voucher->load([
            'voucherCompensations.compensation',
            'payments.paymentMethod',
        ]);

        // Decorate attached compensations with persistent payment-cycle
        // metadata so clients can distinguish ORIGINAL vs ADDITIONAL
        // vouchers without relying on the EP's PAID status.
        $compensations = new Collection(
            $voucher->voucherCompensations->pluck('compensation')->filter()->values()->all()
        );
        $this->additionalPaymentRecords->decorate($compensations, 'compensation');

        return ApiResponse::success($voucher, 'OK');
    }

    /**
     * POST /api/v1/ep/{ep}/voucher
     *
     * Create a voucher for the EP's EC. approved_amount is set from
     * the sum of APPROVED compensations not yet in any voucher.
     */
    public function store(AffectedPerson $ep): JsonResponse
    {
        if (! in_array($ep->status, ['APPROVED', 'PAID'], true)) {
            throw new BusinessRuleException(
                'The EP must be approved before a voucher can be created.',
                'EP_NOT_APPROVED',
            );
        }

        $ec = Ec::where('affected_person_id', $ep->affected_person_id)->first();

        // Bootstrap ONLY for legacy approved EPs that have no EC/compensation
        // rows yet. Running it when compensations exist would overwrite the
        // original (locked) payment data, which must never happen.
        if (! $ec || ! $ec->compensations()->exists()) {
            $this->bootstrapVoucherSource($ep);
            $ec = $this->getEc($ep);
        }

        // For a PAID EP, generate second-payment compensations (payment_cycle 2)
        // from the newly entered loss records (land, crop, structure, tree,
        // business, worker) so the additional voucher carries the new
        // compensation and its grants — original rows stay untouched.
        if ($ep->status === 'PAID') {
            $this->generateAdditionalCompensations($ep, $ec);
        }

        $this->assertNoExisting($ec);

        // Sum of APPROVED compensations not yet in any voucher
        $approvedCompensationIds = Compensation::where('ec_id', $ec->ec_id)
            ->where('status', 'APPROVED')
            ->pluck('compensation_id');

        $attachedIds = VoucherCompensation::whereIn('compensation_id', $approvedCompensationIds)
            ->pluck('compensation_id');

        $unattached = Compensation::where('ec_id', $ec->ec_id)
            ->where('status', 'APPROVED')
            ->whereNotIn('compensation_id', $attachedIds)
            ->get();

        if ($unattached->isEmpty()) {
            throw new BusinessRuleException(
                'No approved compensations available to create a voucher.',
                'NO_APPROVED_COMPENSATIONS',
            );
        }

        $totalAmount = $unattached->sum('approved_amount');

        $voucher = DB::transaction(function () use ($ec, $unattached, $totalAmount) {
            $voucher = Voucher::create([
                'ec_id' => $ec->ec_id,
                'voucher_number' => $this->generateVoucherNumber($ec),
                'voucher_date' => now()->toDateString(),
                'approved_amount' => $totalAmount,
                'payment_status' => 'PENDING',
                'prepared_by' => auth()->id(),
                'remarks' => null,
            ]);

            // Attach all unattached approved compensations
            foreach ($unattached as $comp) {
                VoucherCompensation::create([
                    'voucher_id' => $voucher->voucher_id,
                    'compensation_id' => $comp->compensation_id,
                    'amount' => $comp->approved_amount,
                ]);
            }
            $this->additionalPaymentRecords->attachPendingToVoucher($voucher);

            return $voucher;
        });

        $this->historyService->record(
            'VOUCHER',
            $voucher->voucher_id,
            null,
            'PENDING',
            auth()->id(),
        );

        return ApiResponse::created($voucher, 'Voucher created.');
    }

    /**
     * EP approval is the workflow gate for vouchers. Older approved EPs may
     * not have an EPICAD/EC row yet, so initialize the financial source from
     * the approved EP's legacy compensation fields before creating the voucher.
     */
    private function bootstrapVoucherSource(AffectedPerson $ep): void
    {
        $amounts = $this->ecCalculation->calculate($ep);

        if (array_sum($amounts) <= 0) {
            throw new BusinessRuleException(
                'This approved EP has no compensation amount available for voucher generation.',
                'NO_COMPENSATION_AMOUNT',
            );
        }

        DB::transaction(function () use ($ep, $amounts): void {
            $epicad = Epicad::firstOrCreate(
                ['affected_person_id' => $ep->affected_person_id],
                [
                    'issue_date' => now()->toDateString(),
                    'print_status' => 'NOT_PRINTED',
                ],
            );

            $total = array_sum($amounts);
            $ec = Ec::firstOrCreate(
                ['affected_person_id' => $ep->affected_person_id],
                [
                    'epicad_id' => $epicad->epicad_id,
                    'ec_number' => $this->generateEcNumber($ep),
                    'assessment_date' => now()->toDateString(),
                    'total_assessed_loss' => 0,
                    'total_compensation' => 0,
                    'status' => 'DRAFT',
                    'remarks' => 'Created automatically for direct voucher generation.',
                ],
            );

            foreach ($amounts as $category => $amount) {
                $line = $ec->lineItems()->updateOrCreate(
                    ['category' => $category],
                    [
                        'description' => 'Calculated from approved EP',
                        'assessed_amount' => $amount,
                        'remarks' => null,
                    ],
                );
                $ec->compensations()->updateOrCreate(
                    ['category' => $category],
                    [
                        'assessed_amount' => $line->assessed_amount,
                        'approved_amount' => $amount,
                        'status' => 'APPROVED',
                        'remarks' => 'Approved with EP; EC approval is not required.',
                    ],
                );
            }

            $ec->update([
                'total_assessed_loss' => $total,
                'total_compensation' => $total,
            ]);
        });
    }

    private function getEc(AffectedPerson $ep): Ec
    {
        return Ec::where('affected_person_id', $ep->affected_person_id)
            ->firstOrFail();
    }

    private function generateEcNumber(AffectedPerson $ep): string
    {
        $date = now()->format('Ymd');
        $prefix = "EC-{$date}-";
        $last = Ec::where('ec_number', 'like', $prefix.'%')
            ->orderByDesc('ec_number')
            ->value('ec_number');

        return $prefix.str_pad($last ? ((int) substr($last, -4) + 1) : 1, 4, '0', STR_PAD_LEFT);
    }

    /**
     * PUT /api/v1/ep/{ep}/voucher/{voucher}
     *
     * Only editable when PENDING and no payments.
     */
    public function update(Request $request, AffectedPerson $ep, Voucher $voucher): JsonResponse
    {
        $this->assertBelongsToEp($ep, $voucher);
        $this->assertEditable($voucher);
        $this->assertNoPayments($voucher);

        $data = $request->validate([
            'voucher_date' => 'sometimes|date',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $voucher->update($data);

        return ApiResponse::success($voucher->fresh(), 'Voucher updated.');
    }

    /**
     * DELETE /api/v1/ep/{ep}/voucher/{voucher}
     *
     * Only deletable when PENDING and no payments.
     * Detaches all compensation lines first.
     */
    public function destroy(AffectedPerson $ep, Voucher $voucher): JsonResponse
    {
        $this->assertBelongsToEp($ep, $voucher);
        $this->assertEditable($voucher);
        $this->assertNoPayments($voucher);

        DB::transaction(function () use ($voucher) {
            VoucherCompensation::where('voucher_id', $voucher->voucher_id)->delete();
            $voucher->delete();
        });

        return ApiResponse::success(null, 'Voucher deleted.');
    }

    /**
     * POST /api/v1/ep/{ep}/voucher/{voucher}/verify
     *
     * PENDING → verified (sets verified_by). Payment status unchanged.
     */
    public function verify(AffectedPerson $ep, Voucher $voucher): JsonResponse
    {
        $this->assertBelongsToEp($ep, $voucher);
        $this->assertEditable($voucher);

        $voucher->update(['verified_by' => auth()->id()]);

        $this->historyService->record(
            'VOUCHER',
            $voucher->voucher_id,
            'PENDING',
            'PENDING',
            auth()->id(),
            'Voucher verified',
        );

        return ApiResponse::success($voucher->fresh(), 'Voucher verified.');
    }

    /**
     * POST /api/v1/ep/{ep}/voucher/{voucher}/approve
     *
     * PENDING → approved (sets approved_by). Payment status unchanged.
     */
    public function approve(Request $request, AffectedPerson $ep, Voucher $voucher): JsonResponse
    {
        $this->assertBelongsToEp($ep, $voucher);
        $this->assertEditable($voucher);

        // Manual payment date for the auto-recorded disbursement.
        $data = $request->validate([
            'payment_date' => 'nullable|date|before_or_equal:today',
        ]);
        $paymentDate = $data['payment_date'] ?? now()->toDateString();

        DB::transaction(function () use ($ep, $voucher, $paymentDate): void {
            $voucher->update(['approved_by' => auth()->id()]);
            $this->additionalPaymentRecords->lockVoucherRecords($voucher);

            // Approval completes the disbursement: record the remaining
            // amount as paid so the voucher status becomes PAID instead of
            // staying PENDING in the voucher list.
            $remaining = (float) $voucher->approved_amount
                - (float) $voucher->payments()->sum('amount');
            if ($remaining > 0) {
                $methodId = DB::table('payment_method')
                    ->where('is_active', 1)
                    ->orderByRaw("CASE WHEN code = 'CASH' THEN 0 ELSE 1 END")
                    ->value('payment_method_id');

                if ($methodId !== null) {
                    Payment::create([
                        'voucher_id' => $voucher->voucher_id,
                        'payment_method_id' => $methodId,
                        'amount' => number_format($remaining, 2, '.', ''),
                        'payment_reference' => 'Auto-recorded on voucher approval.',
                        'payment_date' => $paymentDate,
                        'remarks' => 'Full disbursement recorded automatically when the voucher was approved.',
                    ]);
                }
            }

            $paid = (float) $voucher->payments()->sum('amount');
            $newStatus = $paid <= 0
                ? 'PENDING'
                : ($paid >= (float) $voucher->approved_amount ? 'PAID' : 'PARTIAL');
            $voucher->update(['payment_status' => $newStatus]);

            $this->historyService->record(
                'VOUCHER',
                $voucher->voucher_id,
                'PENDING',
                $newStatus,
                auth()->id(),
                'Voucher approved',
            );

            if ($ep->status !== 'PAID') {
                $oldEpStatus = $ep->status;
                $ep->update(['status' => 'PAID']);

                $this->historyService->record(
                    'EP',
                    $ep->affected_person_id,
                    $oldEpStatus,
                    'PAID',
                    auth()->id(),
                    'EP marked paid when voucher was approved.',
                );
            }
        });

        return ApiResponse::success($voucher->fresh(), 'Voucher approved.');
    }

    /**
     * POST /api/v1/ep/{ep}/voucher/{voucher}/cancel
     *
     * PENDING → CANCELLED. Only allowed if no payments recorded.
     */
    public function cancel(AffectedPerson $ep, Voucher $voucher): JsonResponse
    {
        $this->assertBelongsToEp($ep, $voucher);
        $this->assertEditable($voucher);
        $this->assertNoPayments($voucher);

        $voucher->update(['payment_status' => 'CANCELLED']);

        $this->historyService->record(
            'VOUCHER',
            $voucher->voucher_id,
            'PENDING',
            'CANCELLED',
            auth()->id(),
        );

        return ApiResponse::success($voucher->fresh(), 'Voucher cancelled.');
    }

    /**
     * POST /api/v1/ep/{ep}/voucher/{voucher}/compensation
     *
     * Attach an additional approved compensation to this voucher.
     */
    public function attachCompensation(Request $request, AffectedPerson $ep, Voucher $voucher): JsonResponse
    {
        $this->assertBelongsToEp($ep, $voucher);
        $this->assertEditable($voucher);

        $data = $request->validate([
            'compensation_id' => 'required|integer|exists:compensation,compensation_id',
        ]);

        $compensation = Compensation::findOrFail($data['compensation_id']);

        // Must belong to same EC
        if ($compensation->ec_id !== $voucher->ec_id) {
            throw new BusinessRuleException(
                'Compensation does not belong to the same EC as this voucher.',
                'COMPENSATION_EC_MISMATCH',
            );
        }

        if ($compensation->status !== 'APPROVED') {
            throw new BusinessRuleException(
                'Only APPROVED compensations can be attached to a voucher.',
                'COMPENSATION_NOT_APPROVED',
            );
        }

        // Duplicate-payment guard
        if (VoucherCompensation::where('compensation_id', $compensation->compensation_id)->exists()) {
            throw new BusinessRuleException(
                'This compensation is already attached to a voucher.',
                'COMPENSATION_ALREADY_ATTACHED',
            );
        }

        DB::transaction(function () use ($voucher, $compensation) {
            VoucherCompensation::create([
                'voucher_id' => $voucher->voucher_id,
                'compensation_id' => $compensation->compensation_id,
                'amount' => $compensation->approved_amount,
            ]);

            // Recalculate voucher total
            $total = VoucherCompensation::where('voucher_id', $voucher->voucher_id)
                ->sum('amount');

            $voucher->update(['approved_amount' => $total]);
        });

        return ApiResponse::success($voucher->fresh(), 'Compensation attached to voucher.');
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * Create payment_cycle-2 compensations for the losses entered after the
     * original payment (new land, crop, structure, tree, business).
     * Amounts are computed from those new records only. A category is skipped
     * when a second-payment row already exists for it (e.g. added manually in
     * the additional-payment wizard). Original compensation rows are never
     * modified.
     */
    private function generateAdditionalCompensations(AffectedPerson $ep, Ec $ec): void
    {
        $amounts = $this->ecCalculation->calculateAdditional($ep);

        foreach ($amounts as $category => $amount) {
            if ($amount <= 0) {
                continue;
            }

            $alreadyAdded = Compensation::where('ec_id', $ec->ec_id)
                ->where('category', $category)
                ->where('payment_cycle', 2)
                ->exists();
            if ($alreadyAdded) {
                continue;
            }

            $compensation = Compensation::create([
                'ec_id' => $ec->ec_id,
                'category' => $category,
                'assessed_amount' => $amount,
                'approved_amount' => $amount,
                'status' => 'APPROVED',
                'payment_cycle' => 2,
                'remarks' => 'Auto-calculated from additional (2nd payment) loss records.',
            ]);
            $this->additionalPaymentRecords->register($compensation, $ep);
        }
    }

    private function assertBelongsToEp(AffectedPerson $ep, Voucher $voucher): void
    {
        $ec = $this->getEc($ep);

        if ($voucher->ec_id !== $ec->ec_id) {
            throw new BusinessRuleException(
                'Voucher does not belong to this EP.',
                'VOUCHER_NOT_FOUND',
                404,
            );
        }
    }

    private function assertEditable(Voucher $voucher): void
    {
        if (! in_array($voucher->payment_status, self::EDITABLE_STATUSES)) {
            throw new BusinessRuleException(
                'Voucher cannot be modified in '.$voucher->payment_status.' status.',
                'VOUCHER_NOT_EDITABLE',
            );
        }
    }

    private function assertNoExisting(Ec $ec): void
    {
        if (Voucher::where('ec_id', $ec->ec_id)
            ->whereNotIn('payment_status', ['CANCELLED', 'PAID'])
            ->exists()
        ) {
            throw new BusinessRuleException(
                'An active voucher already exists for this EC.',
                'VOUCHER_ALREADY_EXISTS',
            );
        }
    }

    private function assertNoPayments(Voucher $voucher): void
    {
        if ($voucher->payments()->exists()) {
            throw new BusinessRuleException(
                'Cannot modify voucher with existing payments.',
                'VOUCHER_HAS_PAYMENTS',
            );
        }
    }

    private function generateVoucherNumber(Ec $ec): string
    {
        $date = now()->format('Ymd');
        $prefix = "VOU-{$date}-";

        $last = Voucher::where('voucher_number', 'like', $prefix.'%')
            ->orderByDesc('voucher_number')
            ->value('voucher_number');

        if ($last) {
            $seq = (int) substr($last, -4) + 1;
        } else {
            $seq = 1;
        }

        return $prefix.str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
}
