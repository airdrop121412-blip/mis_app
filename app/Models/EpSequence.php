<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ep_sequence — project-wide continuous counter used to mint EP serials,
 * EC numbers and voucher numbers. `last_value` is a reserved word in the
 * schema and is backticked there; Eloquent handles it transparently here.
 *
 * Transaction-safe next-value via atomic UPDATE ... LAST_INSERT_ID() is
 * implemented in the service layer (architecture §8).
 */
class EpSequence extends BaseModel
{
    protected $table = 'ep_sequence';

    protected $primaryKey = 'ep_sequence_id';

    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'sequence_key',
        'last_value',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'last_value' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }
}
