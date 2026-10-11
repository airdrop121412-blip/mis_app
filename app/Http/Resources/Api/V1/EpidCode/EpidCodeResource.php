<?php

namespace App\Http\Resources\Api\V1\EpidCode;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON shape for an EP ID code entry (Admin → EP ID Codes).
 */
class EpidCodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'epid_code_id' => $this->epid_code_id,
            'category' => $this->category,
            'name' => $this->name,
            'code' => $this->code,
            'district_id' => $this->district_id,
            'project_id' => $this->project_id,
            'sort_order' => $this->sort_order,
            'is_active' => (bool) $this->is_active,
            'remarks' => $this->remarks,
        ];
    }
}
