<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * correction_audit — Field-level data-correction audit trail.
 *
 * Append-only. Records WHAT changed (before/after), WHO changed it,
 * and WHY. Complements status_history (which tracks status transitions).
 */
class CorrectionAudit extends BaseModel
{
    protected $table = 'correction_audit';

    protected $primaryKey = 'correction_audit_id';

    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'entity_type',
        'entity_id',
        'ep_id',
        'hh_id',
        'field_name',
        'change_type',
        'old_value',
        'new_value',
        'financial_impact',
        'correction_reason',
        'corrected_by',
        'corrected_at',
        'authorized',
        'authorized_by',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'financial_impact' => 'boolean',
        'authorized' => 'boolean',
        'corrected_at' => 'datetime',
    ];

    public function correctedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by', 'user_id');
    }

    public function authorizedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by', 'user_id');
    }
}
