<?php

namespace App\Services\Audit;

use App\Models\CorrectionAudit;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Field-level correction audit recorder.
 *
 * Complements StatusHistoryService (which tracks status transitions).
 * This service records WHAT data changed, with before/after values.
 * Append-only: no update or delete methods.
 */
class CorrectionAuditService
{
    /**
     * Record a single field correction.
     *
     * @param  string  $entityType  e.g. 'EP', 'LAND', 'TREE', 'EC', etc.
     * @param  int  $entityId  the PK of the affected row
     * @param  string  $fieldName  the column name that changed
     * @param  string|null  $oldValue  previous value
     * @param  string|null  $newValue  new value
     * @param  string  $correctionReason  why corrected
     * @param  int|null  $correctedBy  user_id
     * @param  bool  $financialImpact  whether this is a financial field
     * @param  bool  $authorized  whether authorized by an approver
     * @param  int|null  $authorizedBy  approver user_id
     * @param  string|null  $epId  denormalized EP ID for filtering
     * @param  int|null  $projectId  project for scoping
     */
    public function record(
        string $entityType,
        int $entityId,
        string $fieldName,
        ?string $oldValue,
        ?string $newValue,
        string $correctionReason,
        ?int $correctedBy,
        bool $financialImpact = false,
        bool $authorized = false,
        ?int $authorizedBy = null,
        ?string $epId = null,
        ?int $projectId = null,
        ?string $hhId = null,
        string $changeType = 'EDIT',
    ): CorrectionAudit {
        return CorrectionAudit::create([
            'project_id' => $projectId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'ep_id' => $epId,
            'hh_id' => $hhId,
            'field_name' => $fieldName,
            'change_type' => $changeType,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'financial_impact' => $financialImpact,
            'correction_reason' => $correctionReason,
            'corrected_by' => $correctedBy,
            'corrected_at' => now(),
            'authorized' => $authorized,
            'authorized_by' => $authorizedBy,
        ]);
    }

    /**
     * Record multiple field corrections in a single transaction.
     *
     * @param  array<int, array{
     *     entity_type: string,
     *     entity_id: int,
     *     field_name: string,
     *     old_value: ?string,
     *     new_value: ?string,
     *     financial_impact: bool,
     * }>  $changes
     */
    public function recordBatch(
        array $changes,
        string $correctionReason,
        ?int $correctedBy,
        ?string $epId = null,
        ?int $projectId = null,
        bool $authorized = false,
        ?int $authorizedBy = null,
        ?string $hhId = null,
        string $changeType = 'EDIT',
    ): int {
        $records = array_map(fn (array $change) => [
            'project_id' => $projectId,
            'entity_type' => $change['entity_type'],
            'entity_id' => $change['entity_id'],
            'ep_id' => $epId,
            'hh_id' => $hhId,
            'field_name' => $change['field_name'],
            'change_type' => $changeType,
            'old_value' => $change['old_value'],
            'new_value' => $change['new_value'],
            'financial_impact' => $change['financial_impact'],
            'correction_reason' => $correctionReason,
            'corrected_by' => $correctedBy,
            'corrected_at' => now(),
            'authorized' => $authorized,
            'authorized_by' => $authorizedBy,
        ], $changes);

        DB::table('correction_audit')->insert($records);

        return count($records);
    }

    /**
     * Get correction history for an entity.
     *
     * @return Collection<int, CorrectionAudit>
     */
    public function getHistory(string $entityType, int $entityId)
    {
        return CorrectionAudit::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->with([
                'correctedByUser:user_id,full_name,username,email',
                'authorizedByUser:user_id,full_name,username,email',
            ])
            ->orderByDesc('corrected_at')
            ->orderByDesc('correction_audit_id')
            ->get();
    }

    /**
     * Get all corrections for a project, optionally filtered by entity type,
     * EP/HH search, and change type (EDIT | DELETE).
     *
     * @return LengthAwarePaginator
     */
    public function listCorrections(
        int $projectId,
        ?string $entityType = null,
        ?string $epIdSearch = null,
        int $perPage = 25,
        ?string $changeType = null,
    ) {
        $query = CorrectionAudit::query()
            ->where('project_id', $projectId)
            ->with(['correctedByUser:user_id,full_name,username,email']);

        if ($entityType !== null) {
            $query->where('entity_type', $entityType);
        }
        if ($changeType !== null) {
            $query->where('change_type', $changeType);
        }
        if ($epIdSearch !== null) {
            $query->where(function ($builder) use ($epIdSearch): void {
                $builder->where('ep_id', 'like', "%{$epIdSearch}%")
                    ->orWhere('hh_id', 'like', "%{$epIdSearch}%");
            });
        }

        return $query->orderByDesc('corrected_at')
            ->orderByDesc('correction_audit_id')
            ->paginate(min($perPage, 100));
    }
}
