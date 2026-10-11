<?php

namespace App\Http\Controllers\Api\V1\Correction;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\AffectedPerson;
use App\Models\CorrectionAudit;
use App\Services\Audit\CorrectionAuditService;
use App\Services\Ep\EpDeletionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin-only correction workflow for APPROVED EPs.
 *
 * An APPROVED EP is locked by design. This controller provides the sanctioned
 * escape hatches, all restricted to the ADMIN role:
 *
 *  - LIST   the APPROVED EPs available for correction.
 *  - DELETE a wrongly-approved EP, recording WHY in correction_audit first.
 *           Refused when the EP already has recorded payments, so money that
 *           has actually moved is never erased from the system.
 *  - The EDIT path reuses `PUT /api/v1/ep/{ep}`, which now requires a
 *    `correction_reason` whenever the target EP is APPROVED.
 *
 * PAID EPs are intentionally excluded: they follow the additional-payment
 * workflow, not the correction workflow.
 */
class EpCorrectionController extends Controller
{
    public function __construct(
        private readonly CorrectionAuditService $correctionService,
        private readonly EpDeletionService $deletionService,
    ) {}

    /**
     * GET /api/v1/corrections/eps
     *
     * List APPROVED EPs available for correction. PAID EPs never appear here.
     */
    public function index(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        $query = AffectedPerson::query()
            ->where('status', 'APPROVED')
            ->with(['district:district_id,name', 'upazila:upazila_id,name']);

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->integer('project_id'));
        }

        if ($request->filled('district_id')) {
            $query->where('district_id', $request->integer('district_id'));
        }

        if ($request->filled('upazila_id')) {
            $query->where('upazila_id', $request->integer('upazila_id'));
        }

        if ($request->filled('q')) {
            $search = trim((string) $request->input('q'));
            $query->where(function ($builder) use ($search): void {
                $builder->where('hh_id', 'like', "%{$search}%")
                    ->orWhere('ep_id', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('nid_number', 'like', "%{$search}%");
            });
        }

        $query->orderByRaw('CAST(hh_id AS UNSIGNED) ASC')
            ->orderBy('affected_person_id');

        $perPage = min((int) $request->input('per_page', 25), 100);
        $paginator = $query->paginate($perPage);

        $items = collect($paginator->items())->map(fn (AffectedPerson $ep): array => [
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
            'status' => $ep->status,
        ])->values();

        return ApiResponse::success(
            $items,
            'OK',
            200,
            [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        );
    }

    /**
     * GET /api/v1/corrections/history
     *
     * Correction history for a project. Each row carries the HH ID, the cause,
     * the change type (EDIT | DELETE) and the acting user (email when present,
     * otherwise full name / username).
     */
    public function history(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        $projectId = $request->input('project_id');
        if (! $projectId) {
            return ApiResponse::error('project_id is required.', 'VALIDATION_ERROR', 422);
        }

        $paginator = $this->correctionService->listCorrections(
            (int) $projectId,
            $request->filled('entity_type') ? (string) $request->input('entity_type') : null,
            $request->filled('q') ? trim((string) $request->input('q')) : null,
            (int) $request->input('per_page', 25),
            $request->filled('change_type') ? (string) $request->input('change_type') : null,
        );

        $items = collect($paginator->items())->map(function (CorrectionAudit $row): array {
            $user = $row->correctedByUser;

            return [
                'correction_audit_id' => $row->correction_audit_id,
                'ep_id' => $row->ep_id,
                'hh_id' => $row->hh_id,
                'entity_type' => $row->entity_type,
                'change_type' => $row->change_type,
                'field_name' => $row->field_name,
                'old_value' => $row->old_value,
                'new_value' => $row->new_value,
                'cause' => $row->correction_reason,
                'financial_impact' => (bool) $row->financial_impact,
                'authorized' => (bool) $row->authorized,
                'user_id' => $row->corrected_by,
                'user_display' => $user?->email ?: ($user?->full_name ?: $user?->username),
                'user_email' => $user?->email,
                'user_name' => $user?->full_name,
                'user_username' => $user?->username,
                'corrected_at' => $row->corrected_at,
            ];
        })->values();

        return ApiResponse::success(
            $items,
            'OK',
            200,
            [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
        );
    }

    /**
     * DELETE /api/v1/corrections/eps/{ep}
     *
     * Admin-only hard delete of an APPROVED EP. The reason is written to
     * correction_audit first (that table has no FK, so the record survives the
     * delete) and only then is the EP graph removed.
     */
    public function destroy(Request $request, AffectedPerson $ep): JsonResponse
    {
        $this->assertAdmin($request);

        if ($ep->status !== 'APPROVED') {
            throw new BusinessRuleException(
                'Only APPROVED EPs can be deleted from the Correction screen (current: '.$ep->status.').',
                'EP_NOT_APPROVED',
            );
        }

        $reason = trim((string) $request->input('correction_reason', ''));
        if ($reason === '') {
            throw new BusinessRuleException(
                'A reason is required to delete an APPROVED EP.',
                'CORRECTION_REASON_REQUIRED',
            );
        }

        if ($this->deletionService->hasPayments($ep)) {
            throw new BusinessRuleException(
                'This EP has recorded payments and cannot be deleted. Correct it with Edit instead.',
                'EP_HAS_PAYMENTS',
            );
        }

        $epId = $ep->affected_person_id;
        $projectId = $ep->project_id;
        $epCode = $ep->ep_id;
        $hhId = $ep->hh_id;

        DB::transaction(function () use ($epId, $projectId, $epCode, $hhId, $reason): void {
            // Written before the delete so the audit row is never orphaned by
            // a partially-removed graph.
            $this->correctionService->record(
                'EP',
                $epId,
                '__DELETE__',
                $epCode,
                null,
                $reason,
                auth()->id(),
                false,
                true,
                auth()->id(),
                $epCode,
                $projectId,
                $hhId,
                'DELETE',
            );

            $this->deletionService->deleteGraph($epId);

            // Keep the serial behaviour identical to a DRAFT delete: when the
            // project is left with no EPs, the serial restarts from #0001.
            $remaining = DB::table('affected_person')
                ->where('project_id', $projectId)
                ->count();

            if ($remaining === 0) {
                DB::table('ep_sequence')
                    ->where('project_id', $projectId)
                    ->where('sequence_key', 'EP_SERIAL')
                    ->update(['last_value' => 0]);
            }
        });

        return ApiResponse::success(null, 'Approved EP deleted and the reason saved to Correction History.');
    }

    /**
     * Correction is an administrator-only capability.
     */
    private function assertAdmin(Request $request): void
    {
        $user = $request->user();

        if (! $user || ! $user->roles()->where('code', 'ADMIN')->exists()) {
            throw new BusinessRuleException(
                'Only an administrator can correct an APPROVED EP.',
                'FORBIDDEN',
                403,
            );
        }
    }
}
