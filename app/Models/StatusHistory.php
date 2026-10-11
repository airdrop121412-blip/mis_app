<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * status_history — generic, polymorphic audit trail for
 * EP | EC | COMPENSATION | VOUCHER | ALLOWANCE. entity_id intentionally has
 * no FK; changed_by -> users is ON DELETE SET NULL so the trail survives
 * user removal.
 */
class StatusHistory extends BaseModel
{
    protected $table = 'status_history';

    protected $primaryKey = 'status_history_id';

    /** @var list<string> */
    protected $fillable = [
        'entity_type',
        'entity_id',
        'previous_status',
        'new_status',
        'changed_by',
        'changed_at',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'changed_at' => 'datetime',
    ];

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by', 'user_id');
    }
}
