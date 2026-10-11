<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * epid_code — configurable short codes consumed by the EP ID builder.
 *
 * Row = one entry under a category (district, upazila, project_phase,
 * title, non_tile, loss_category). Uniqueness is per category, enforced
 * by the table → see EpidCodeStoreRequest / EpidCodeUpdateRequest.
 */
class EpidCode extends BaseModel
{
    public const CATEGORY_DISTRICT = 'district';

    public const CATEGORY_UPAZILA = 'upazila';

    public const CATEGORY_PROJECT_PHASE = 'project_phase';

    public const CATEGORY_TITLE = 'title';

    public const CATEGORY_NON_TILE = 'non_tile';

    public const CATEGORY_LOSS_CATEGORY = 'loss_category';

    /** Ordered list of supported categories with admin-facing labels. */
    public const CATEGORIES = [
        self::CATEGORY_TITLE => 'Title',
        self::CATEGORY_NON_TILE => 'Non-Tile',
        self::CATEGORY_LOSS_CATEGORY => 'Loss Category',
        self::CATEGORY_DISTRICT => 'District',
        self::CATEGORY_UPAZILA => 'Upazila',
        self::CATEGORY_PROJECT_PHASE => 'Project Phase',
    ];

    /**
     * Max code length per category. The EP ID prefix is built by simple
     * concatenation (District + Upazila + Phase + Title/Non-Tile + Loss) and
     * is parsed via the '#' serial separator, so codes do NOT need to be
     * single characters:
     * - District: 3 chars — Bangladesh has 64 districts, codes 10+ are valid.
     * - Upazila: 2 chars — codes are unique per district (max ~14 upazilas).
     * - Phase: 2 chars, Title/Non-Tile: 1 char (T/N style flags).
     * - Loss: 10 chars — loss categories are combinations (Land, Structure,
     *   Tree, Tenant, ...) and quickly exceed 9 single-digit codes.
     */
    public const CODE_MAX_LENGTH = [
        self::CATEGORY_DISTRICT => 3,
        self::CATEGORY_UPAZILA => 2,
        self::CATEGORY_PROJECT_PHASE => 2,
        self::CATEGORY_TITLE => 1,
        self::CATEGORY_NON_TILE => 1,
        self::CATEGORY_LOSS_CATEGORY => 10,
    ];

    protected $table = 'epid_code';

    protected $primaryKey = 'epid_code_id';

    /** @var list<string> */
    protected $fillable = [
        'category',
        'name',
        'code',
        'district_id',
        'project_id',
        'sort_order',
        'is_active',
        'remarks',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'district_id' => 'integer',
        'project_id' => 'integer',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class, 'district_id', 'district_id');
    }
}
