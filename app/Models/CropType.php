<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * crop_type — crop classification master.
 */
class CropType extends BaseModel
{
    protected $table = 'crop_type';

    protected $primaryKey = 'crop_type_id';

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
        return $this->hasMany(Crop::class, 'crop_type_id', 'crop_type_id');
    }

    public function rates(): HasMany
    {
        return $this->hasMany(CropRate::class, 'crop_type_id', 'crop_type_id');
    }
}
