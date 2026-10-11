<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * epicad — the EP card, 1:1 with an affected person.
 * print_status: NOT_PRINTED | PRINTED | REPRINTED.
 */
class Epicad extends BaseModel
{
    protected $table = 'epicad';

    protected $primaryKey = 'epicad_id';

    /** @var list<string> */
    protected $fillable = [
        'affected_person_id',
        'issue_date',
        'print_status',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'issue_date' => 'date',
    ];

    public function affectedPerson(): BelongsTo
    {
        return $this->belongsTo(AffectedPerson::class, 'affected_person_id', 'affected_person_id');
    }

    public function ec(): HasOne
    {
        return $this->hasOne(Ec::class, 'epicad_id', 'epicad_id');
    }
}
