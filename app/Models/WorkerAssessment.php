<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * worker_assessment — manual loss amount for a worker (no rate master).
 * 1:1 with worker.
 */
class WorkerAssessment extends BaseModel
{
    protected $table = 'worker_assessment';

    protected $primaryKey = 'worker_assessment_id';

    /** @var list<string> */
    protected $fillable = [
        'worker_id',
        'loss_amount',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'loss_amount' => 'decimal:2',
    ];

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'worker_id', 'worker_id');
    }
}
