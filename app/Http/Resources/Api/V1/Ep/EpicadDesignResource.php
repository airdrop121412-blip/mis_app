<?php

namespace App\Http\Resources\Api\V1\Ep;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for epicad_design (EPICAD card template).
 */
class EpicadDesignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'epicad_design_id' => $this->epicad_design_id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'orientation' => $this->orientation,
            'page_size' => $this->page_size,
            'layout_json' => $this->layout_json,
            'background_url' => $this->background_url,
            'css' => $this->css,
            'html_template' => $this->html_template,
            'docx_orig_filename' => $this->docx_orig_filename,
            'is_active' => $this->is_active,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
