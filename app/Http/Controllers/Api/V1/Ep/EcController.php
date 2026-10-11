<?php

namespace App\Http\Controllers\Api\V1\Ep;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AffectedPerson;
use App\Models\Ec;
use App\Models\Epicad;
use App\Services\Audit\StatusHistoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * EC (Evidence Collection / Damage Compensation Assessment) —
 * exactly ONE per EPICAD/EP (architecture §5.3).
 *
 * status: DRAFT | SUBMITTED | VERIFIED | APPROVED | REJECTED.
 */
class EcController extends Controller
{
    private const VALID_TRANSITIONS = [
        'DRAFT' => ['SUBMITTED', 'REJECTED'],
        'SUBMITTED' => ['VERIFIED', 'REJECTED'],
        'VERIFIED' => ['APPROVED', 'REJECTED'],
    ];

    private const EDITABLE_STATUSES = ['DRAFT'];

    public function __construct(
        private readonly StatusHistoryService $historyService,
    ) {}

    /**
     * GET /api/v1/ep/{ep}/ec
     */
    public function show(AffectedPerson $ep): JsonResponse
    {
        $ec = Ec::where('affected_person_id', $ep->affected_person_id)
            ->with('lineItems')
            ->firstOrFail();

        return ApiResponse::success($ec, 'OK');
    }

    /**
     * POST /api/v1/ep/{ep}/ec
     *
     * Creates an internal EPICAD reference when needed. Exactly one EC per EP.
     */
    public function store(AffectedPerson $ep): JsonResponse
    {
        $this->assertNoExisting($ep);

        $epicad = Epicad::firstOrCreate(
            ['affected_person_id' => $ep->affected_person_id],
            [
                'issue_date' => now()->toDateString(),
                'print_status' => 'NOT_PRINTED',
            ],
        );
        $ecNumber = $this->generateEcNumber($ep);

        $ec = Ec::create([
            'affected_person_id' => $ep->affected_person_id,
            'epicad_id' => $epicad->epicad_id,
            'ec_number' => $ecNumber,
            'assessment_date' => now()->toDateString(),
            'total_assessed_loss' => '0.00',
            'total_compensation' => '0.00',
            'status' => 'DRAFT',
        ]);

        $this->historyService->record(
            'EC',
            $ec->ec_id,
            null,
            'DRAFT',
            auth()->id(),
        );

        return ApiResponse::created($ec, 'EC created.');
    }

    /**
     * PUT /api/v1/ep/{ep}/ec
     *
     * Only editable in DRAFT.
     */
    public function update(Request $request, AffectedPerson $ep): JsonResponse
    {
        $ec = $this->getEc($ep);
        $this->assertEditable($ec);

        $data = $request->validate([
            'assessment_date' => 'nullable|date',
            'remarks' => 'nullable|string|max:2000',
        ]);

        $ec->update($data);

        return ApiResponse::success($ec->fresh(), 'EC updated.');
    }

    // ── Status transitions ──────────────────────────────────────

    /**
     * POST /api/v1/ep/{ep}/ec/submit
     */
    public function submit(AffectedPerson $ep): JsonResponse
    {
        $ec = $this->getEc($ep);
        $this->transition($ec, 'SUBMITTED');

        return ApiResponse::success($ec->fresh(), 'EC submitted.');
    }

    /**
     * POST /api/v1/ep/{ep}/ec/verify
     */
    public function verify(AffectedPerson $ep): JsonResponse
    {
        $ec = $this->getEc($ep);
        $this->transition($ec, 'VERIFIED');

        return ApiResponse::success($ec->fresh(), 'EC verified.');
    }

    /**
     * POST /api/v1/ep/{ep}/ec/approve
     */
    public function approve(AffectedPerson $ep): JsonResponse
    {
        $ec = $this->getEc($ep);
        $this->transition($ec, 'APPROVED');

        return ApiResponse::success($ec->fresh(), 'EC approved.');
    }

    /**
     * POST /api/v1/ep/{ep}/ec/reject  (architecture §5.6 #45)
     *
     * Requires a remarks reason. Rejected EC may be edited and resubmitted.
     */
    public function reject(AffectedPerson $ep, Request $request): JsonResponse
    {
        $request->validate([
            'remarks' => 'required|string|max:2000',
        ]);

        $ec = $this->getEc($ep);
        $this->transition($ec, 'REJECTED', $request->input('remarks'));

        return ApiResponse::success($ec->fresh(), 'EC rejected.');
    }

    /**
     * GET /api/v1/ep/{ep}/ec/history  (architecture §5.6 #46)
     *
     * Returns the authoritative status_history for this EC.
     */
    public function history(AffectedPerson $ep): JsonResponse
    {
        $ec = $this->getEc($ep);
        $history = $this->historyService->getHistory('EC', $ec->ec_id);

        return ApiResponse::success($history, 'OK');
    }

    /**
     * POST /api/v1/ep/{ep}/ec/recalculate
     *
     * Recalculate total_assessed_loss from line items.
     */
    public function recalculate(AffectedPerson $ep): JsonResponse
    {
        $ec = $this->getEc($ep);
        $this->assertEditable($ec);

        $total = $ec->lineItems()->sum('assessed_amount');

        $ec->update(['total_assessed_loss' => $total]);

        return ApiResponse::success($ec->fresh(), 'EC totals recalculated.');
    }

    // ── Private helpers ─────────────────────────────────────────

    private function getEc(AffectedPerson $ep): Ec
    {
        return Ec::where('affected_person_id', $ep->affected_person_id)
            ->firstOrFail();
    }

    private function assertEditable(Ec $ec): void
    {
        if (! in_array($ec->status, self::EDITABLE_STATUSES)) {
            throw new BusinessRuleException(
                'EC cannot be modified in '.$ec->status.' status.',
                'EC_NOT_EDITABLE',
            );
        }
    }

    private function assertNoExisting(AffectedPerson $ep): void
    {
        if (Ec::where('affected_person_id', $ep->affected_person_id)->exists()) {
            throw new BusinessRuleException(
                'EC already exists for this EP.',
                'EC_ALREADY_EXISTS',
            );
        }
    }

    private function transition(Ec $ec, string $newStatus, ?string $remarks = null): void
    {
        $currentStatus = $ec->status;
        $allowed = self::VALID_TRANSITIONS[$currentStatus] ?? [];

        if (! in_array($newStatus, $allowed)) {
            throw new BusinessRuleException(
                "Cannot transition EC from {$currentStatus} to {$newStatus}.",
                'INVALID_TRANSITION',
            );
        }

        $ec->update(['status' => $newStatus]);

        $this->historyService->record(
            'EC',
            $ec->ec_id,
            $currentStatus,
            $newStatus,
            auth()->id(),
            $remarks,
        );
    }

    private function generateEcNumber(AffectedPerson $ep): string
    {
        $date = now()->format('Ymd');
        $prefix = "EC-{$date}-";

        $lastNumber = DB::table('ec')
            ->where('ec_number', 'LIKE', $prefix.'%')
            ->orderByDesc('ec_number')
            ->value('ec_number');

        if ($lastNumber) {
            $seq = (int) substr($lastNumber, -4) + 1;
        } else {
            $seq = 1;
        }

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
