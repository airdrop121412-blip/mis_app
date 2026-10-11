<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * epicad_design — reusable EPICAD card template (visual layout and/or
 * uploaded HTML / DOCX). One active design per project drives the EPICAD
 * tab output.
 */
class EpicadDesign extends BaseModel
{
    protected $table = 'epicad_design';

    protected $primaryKey = 'epicad_design_id';

    /** @var list<string> */
    protected $fillable = [
        'project_id',
        'name',
        'orientation',
        'page_size',
        'layout_json',
        'background_url',
        'css',
        'html_template',
        'docx_orig_filename',
        'is_active',
        'created_by',
        'updated_by',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id', 'project_id');
    }
}
