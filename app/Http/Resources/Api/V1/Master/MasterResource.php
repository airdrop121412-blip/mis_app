<?php

namespace App\Http\Resources\Api\V1\Master;

use App\Models\BaseModel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Generic JSON shape for the 27 master lookup tables (architecture §5.2).
 *
 * Returns: id, code (nullable for village), name, is_active, remarks,
 * plus any extra columns present on the model (sort_order, district_id, etc.)
 */
class MasterResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var BaseModel $this */

        // Core fields present on every master table.
        $pk = $this->getKeyName();       // e.g. 'gender_id'
        $result = [
            'id' => $this->{$pk},
            'code' => $this->getRawOriginal('code'),  // null for village
            'name' => $this->name,
            'is_active' => $this->is_active,
            'remarks' => $this->remarks,
        ];

        // Extra columns that some masters carry (sort_order, district_id, upazila_id).
        $extras = ['sort_order', 'district_id', 'upazila_id', 'effective_date'];
        $modelAttrs = $this->resource->getAttributes();
        foreach ($extras as $col) {
            if (array_key_exists($col, $modelAttrs)) {
                $result[$col] = $this->{$col};
            }
        }

        return $result;
    }
}
