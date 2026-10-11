<?php

namespace App\Services\Audit;

use App\Models\StatusHistory;
use Illuminate\Database\Eloquent\Collection;

/**
 * Generic status-history recorder (architecture §21).
 *
 * One method for every status transition: EP, EC, Compensation, Voucher,
 * Allowance, EPICAD. Append-only; no update/delete.
 */
class StatusHistoryService
{
    /**
     * Record a status transition (architecture §21).
     *
     * @param  string  $entityType  e.g. 'EP','EC','COMPENSATION','VOUCHER','ALLOWANCE','EPICAD'
     * @param  int  $entityId  the primary key of the entity
     * @param  string|null  $previousStatus  null for creation events
     * @param  int|null  $changedBy  user_id
     */
    public function record(
        string $entityType,
        int $entityId,
        ?string $previousStatus,
        string $newStatus,
        ?int $changedBy,
        ?string $remarks = null,
    ): StatusHistory {
        return StatusHistory::create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'changed_by' => $changedBy,
            'changed_at' => now(),
            'remarks' => $remarks,
        ]);
    }

    /**
     * Get ordered history for an entity.
     *
     * @return Collection<int, StatusHistory>
     */
    public function getHistory(string $entityType, int $entityId)
    {
        return StatusHistory::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->with('changedBy:user_id,full_name,username')
            ->orderBy('changed_at')
            ->orderBy('status_history_id')
            ->get();
    }
}
