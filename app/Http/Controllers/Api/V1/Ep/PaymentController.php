<?php

namespace App\Http\Controllers\Api\V1\Ep;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AffectedPerson;
use App\Models\Ec;
use App\Models\Payment;
use App\Models\Voucher;
use App\Services\Audit\StatusHistoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Payment — actual disbursement against a voucher.
 *
 * Business rules:
 *  - Sum(payments) must not exceed voucher.approved_amount
 *  - Payment only allowed when voucher payment_status != CANCELLED
 *  - Voiding a payment recalculates voucher payment_status
 */
class PaymentController extends Controller
{
    public function __construct(
        private readonly StatusHistoryService $historyService,
    ) {}

    /**
     * GET /api/v1/ep/{ep}/voucher/{voucher}/payment
     */
    public function index(AffectedPerson $ep, Voucher $voucher): JsonResponse
    {
        $this->assertVoucherBelongsToEp($ep, $voucher);

        $payments = Payment::where('voucher_id', $voucher->voucher_id)
            ->with('paymentMethod:payment_method_id,name')
            ->orderBy('payment_id')
            ->get();

        return ApiResponse::success($payments, 'OK');
    }

    /**
     * GET /api/v1/payments
     *
     * Payment register for fully paid EPs.
     */
    public function register(Request $request): JsonResponse
    {
        $query = AffectedPerson::query()
            ->where('status', 'PAID')
            ->with(['district:district_id,name', 'upazila:upazila_id,name', 'ec.vouchers.payments'])
            ->withExists('additionalPaymentRecords as has_additional')
            ->orderByRaw('CAST(hh_id AS UNSIGNED) ASC')
            ->orderBy('affected_person_id');

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

        $items = $query->get()->map(function (AffectedPerson $ep): array {
            $paid = $ep->ec?->vouchers
                ?->flatMap(fn ($voucher) => $voucher->payments)
                ->sum('amount') ?? 0;
            $approved = $ep->ec?->vouchers
                ?->whereNotIn('payment_status', ['CANCELLED'])
                ->sum('approved_amount') ?? 0;

            $hasAdditional = (bool) $ep->has_additional;

            return [
                'affected_person_id' => $ep->affected_person_id,
                'hh_id' => $ep->hh_id,
                'ep_id' => $ep->ep_id,
                'name' => $ep->name,
                'father_name' => $ep->father_name,
                'nid_number' => $ep->nid_number,
                'mobile' => $ep->mobile,
                'mouza' => $ep->mouza,
                'union_name' => $ep->union_name,
                'upazila' => $ep->upazila?->name,
                'district' => $ep->district?->name,
                'category_of_loss' => $ep->category_of_loss,
                'compensation_amount' => number_format((float) ($paid ?: $approved), 2, '.', ''),
                'status' => $hasAdditional ? 'PAID & ADDITIONAL' : $ep->status,
                'has_additional' => $hasAdditional,
            ];
        });

        return ApiResponse::success($items, 'OK');
    }

    /**
     * POST /api/v1/ep/{ep}/voucher/{voucher}/payment
     *
     * Records a payment against the voucher. Enforces:
     *  - Voucher must not be CANCELLED
     *  - Sum of all payments must not exceed voucher.approved_amount
     */
    public function store(Request $request, AffectedPerson $ep, Voucher $voucher): JsonResponse
    {
        $this->assertVoucherBelongsToEp($ep, $voucher);
        $this->assertVoucherNotCancelled($voucher);

        $data = $request->validate([
            'payment_method_id' => 'required|integer|exists:payment_method,payment_method_id',
            'amount' => 'required|numeric|min:0.01',
            'payment_reference' => 'nullable|string|max:100',
            'payment_date' => 'required|date',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $this->assertPaymentWithinLimit($voucher, $data['amount']);

        $payment = DB::transaction(function () use ($voucher, $data) {
            $payment = Payment::create([
                'voucher_id' => $voucher->voucher_id,
                'payment_method_id' => $data['payment_method_id'],
                'amount' => $data['amount'],
                'payment_reference' => $data['payment_reference'] ?? null,
                'payment_date' => $data['payment_date'],
                'remarks' => $data['remarks'] ?? null,
            ]);

            $this->recalculatePaymentStatus($voucher);

            return $payment;
        });

        return ApiResponse::created($payment, 'Payment recorded.');
    }

    /**
     * DELETE /api/v1/ep/{ep}/voucher/{voucher}/payment/{payment}
     *
     * Void (delete) a payment. Voucher must not be CANCELLED.
     */
    public function destroy(AffectedPerson $ep, Voucher $voucher, Payment $payment): JsonResponse
    {
        $this->assertVoucherBelongsToEp($ep, $voucher);
        $this->assertPaymentBelongsToVoucher($voucher, $payment);
        $this->assertVoucherNotCancelled($voucher);

        DB::transaction(function () use ($voucher, $payment) {
            $payment->delete();
            $this->recalculatePaymentStatus($voucher);
        });

        return ApiResponse::success(null, 'Payment voided.');
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function assertVoucherBelongsToEp(AffectedPerson $ep, Voucher $voucher): void
    {
        $ec = Ec::where('affected_person_id', $ep->affected_person_id)
            ->first();

        if (! $ec || $voucher->ec_id !== $ec->ec_id) {
            throw new BusinessRuleException(
                'Voucher does not belong to this EP.',
                'VOUCHER_NOT_FOUND',
                404,
            );
        }
    }

    private function assertVoucherNotCancelled(Voucher $voucher): void
    {
        if ($voucher->payment_status === 'CANCELLED') {
            throw new BusinessRuleException(
                'Cannot record payment against a cancelled voucher.',
                'VOUCHER_CANCELLED',
            );
        }
    }

    private function assertPaymentWithinLimit(Voucher $voucher, float $newAmount): void
    {
        $existingTotal = Payment::where('voucher_id', $voucher->voucher_id)
            ->sum('amount');

        $proposedTotal = (float) $existingTotal + $newAmount;

        if ($proposedTotal > (float) $voucher->approved_amount) {
            throw new BusinessRuleException(
                'Payment would exceed voucher approved amount. Remaining: '
                    .number_format((float) $voucher->approved_amount - (float) $existingTotal, 2),
                'PAYMENT_EXCEEDS_VOUCHER',
            );
        }
    }

    private function assertPaymentBelongsToVoucher(Voucher $voucher, Payment $payment): void
    {
        if ($payment->voucher_id !== $voucher->voucher_id) {
            throw new BusinessRuleException(
                'Payment does not belong to this voucher.',
                'PAYMENT_NOT_FOUND',
                404,
            );
        }
    }

    /**
     * Recalculate voucher payment_status based on actual payments.
     *
     * PENDING → no payments
     * PARTIAL → sum(payments) < approved_amount
     * PAID    → sum(payments) >= approved_amount
     */
    private function recalculatePaymentStatus(Voucher $voucher): void
    {
        $totalPaid = Payment::where('voucher_id', $voucher->voucher_id)
            ->sum('amount');

        $approvedAmount = (float) $voucher->approved_amount;

        if ($totalPaid <= 0) {
            $newStatus = 'PENDING';
        } elseif ($totalPaid >= $approvedAmount) {
            $newStatus = 'PAID';
        } else {
            $newStatus = 'PARTIAL';
        }

        if ($voucher->payment_status !== $newStatus) {
            $oldStatus = $voucher->payment_status;
            $voucher->update(['payment_status' => $newStatus]);

            $this->historyService->record(
                'VOUCHER',
                $voucher->voucher_id,
                $oldStatus,
                $newStatus,
                auth()->id(),
            );
        }

        $ep = $voucher->ec?->affectedPerson;
        if ($ep && $newStatus === 'PAID' && $ep->status !== 'PAID') {
            $oldEpStatus = $ep->status;
            $ep->update(['status' => 'PAID']);
            $this->historyService->record(
                'EP',
                $ep->affected_person_id,
                $oldEpStatus,
                'PAID',
                auth()->id(),
                'Voucher fully paid.',
            );
        }
    }
}
