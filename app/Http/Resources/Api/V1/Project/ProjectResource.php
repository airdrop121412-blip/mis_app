<?php

namespace App\Http\Resources\Api\V1\Project;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for the project resource (architecture §5.3).
 */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'project_id' => $this->project_id,
            'project_code' => $this->project_code,
            'name' => $this->name,
            'phase' => $this->phase,
            'description' => $this->description,
            'start_date' => $this->start_date?->format('Y-m-d'),
            'end_date' => $this->end_date?->format('Y-m-d'),
            'is_active' => $this->is_active,
            'remarks' => $this->remarks,
        ];
    }
}
