<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * tenant_type — RESIDENTIAL / COMMERCIAL tenant classification.
 */
class TenantType extends BaseModel
{
    protected $table = 'tenant_type';

    protected $primaryKey = 'tenant_type_id';

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

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class, 'tenant_type_id', 'tenant_type_id');
    }
}
