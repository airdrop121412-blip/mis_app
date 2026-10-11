<?php

namespace App\Http\Controllers\Api\V1\Ep;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AffectedPerson;
use App\Models\Epicad;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * EPICAD (EP Card) — 1:1 with Affected Person (architecture §5.2).
 *
 * Created after EP is submitted; printable card summarising the EP.
 * print_status: NOT_PRINTED | PRINTED | REPRINTED.
 */
class EpicadController extends Controller
{
    private const EDITABLE_PRINT_STATUSES = ['NOT_PRINTED'];

    /**
     * GET /api/v1/ep/{ep}/epicad
     */
    public function show(AffectedPerson $ep): JsonResponse
    {
        $epicad = Epicad::where('affected_person_id', $ep->affected_person_id)
            ->firstOrFail();

        return ApiResponse::success($epicad, 'OK');
    }

    /**
     * POST /api/v1/ep/{ep}/epicad
     *
     * Only when EP is SUBMITTED, VERIFIED, or APPROVED.
     * Exactly one EPICAD per EP.
     */
    public function store(AffectedPerson $ep): JsonResponse
    {
        $this->assertSubmittable($ep);
        $this->assertNoExisting($ep);

        $epicad = Epicad::create([
            'affected_person_id' => $ep->affected_person_id,
            'issue_date' => now()->toDateString(),
            'print_status' => 'NOT_PRINTED',
        ]);

        return ApiResponse::created($epicad, 'EPICAD created.');
    }

    /**
     * PUT /api/v1/ep/{ep}/epicad
     *
     * Only when print_status is NOT_PRINTED.
     */
    public function update(Request $request, AffectedPerson $ep): JsonResponse
    {
        $epicad = $this->getEpicad($ep);
        $this->assertEditable($epicad);

        $data = $request->validate([
            'issue_date' => 'nullable|date',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $epicad->update($data);

        return ApiResponse::success($epicad->fresh(), 'EPICAD updated.');
    }

    /**
     * DELETE /api/v1/ep/{ep}/epicad
     *
     * Only when print_status is NOT_PRINTED.
     */
    public function destroy(AffectedPerson $ep): JsonResponse
    {
        $epicad = $this->getEpicad($ep);
        $this->assertEditable($epicad);

        // Cannot delete if EC exists
        if ($epicad->ec) {
            throw new BusinessRuleException(
                'Cannot delete EPICAD with an associated EC.',
                'EPICAD_HAS_EC',
            );
        }

        $epicad->delete();

        return ApiResponse::success(null, 'EPICAD deleted.');
    }

    /**
     * POST /api/v1/ep/{ep}/epicad/print
     *
     * Transition print_status: NOT_PRINTED → PRINTED or PRINTED → REPRINTED.
     */
    public function print(AffectedPerson $ep): JsonResponse
    {
        $epicad = $this->getEpicad($ep);

        $transitions = [
            'NOT_PRINTED' => 'PRINTED',
            'PRINTED' => 'REPRINTED',
        ];

        $newStatus = $transitions[$epicad->print_status] ?? null;

        if ($newStatus === null) {
            throw new BusinessRuleException(
                'EPICAD cannot be printed from status '.$epicad->print_status.'.',
                'INVALID_PRINT_TRANSITION',
            );
        }

        $epicad->update(['print_status' => $newStatus]);

        return ApiResponse::success($epicad->fresh(), 'EPICAD marked as '.$newStatus.'.');
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function getEpicad(AffectedPerson $ep): Epicad
    {
        return Epicad::where('affected_person_id', $ep->affected_person_id)
            ->firstOrFail();
    }

    private function assertSubmittable(AffectedPerson $ep): void
    {
        if (! in_array($ep->status, ['SUBMITTED', 'VERIFIED', 'APPROVED'])) {
            throw new BusinessRuleException(
                'EPICAD can only be created when EP is SUBMITTED, VERIFIED, or APPROVED.',
                'EP_NOT_SUBMITTED',
            );
        }
    }

    private function assertNoExisting(AffectedPerson $ep): void
    {
        if (Epicad::where('affected_person_id', $ep->affected_person_id)->exists()) {
            throw new BusinessRuleException(
                'EPICAD already exists for this EP.',
                'EPICAD_ALREADY_EXISTS',
            );
        }
    }

    private function assertEditable(Epicad $epicad): void
    {
        if (! in_array($epicad->print_status, self::EDITABLE_PRINT_STATUSES)) {
            throw new BusinessRuleException(
                'EPICAD cannot be modified in '.$epicad->print_status.' status.',
                'EPICAD_NOT_EDITABLE',
            );
        }
    }
}
