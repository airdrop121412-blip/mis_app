<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * crop_season — e.g. ROBHI / KHARIF-1 / KHARIF-2.
 */
class CropSeason extends BaseModel
{
    protected $table = 'crop_season';

    protected $primaryKey = 'crop_season_id';

    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'is_active',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function crops(): HasMany
    {
        return $this->hasMany(Crop::class, 'crop_season_id', 'crop_season_id');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(CropRate::class, 'crop_season_id', 'crop_season_id');
    }
}
