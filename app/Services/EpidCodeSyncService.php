<?php

namespace App\Services;

use App\Models\District;
use App\Models\EpidCode;
use App\Models\LandStatus;
use App\Models\Upazila;

/**
 * Mirrors EP ID codes into the master lookup tables so that codes added
 * under Admin → EP ID Codes also appear in the app's dropdowns.
 *
 * Mapping:
 *   district       → district table
 *   upazila        → upazila table (linked to district_id when provided)
 *   title          → land_status table
 *   project_phase  → phases are read straight from epid_code, no mirror
 *   non_tile       → no dropdown, no mirror
 *   loss_category  → no master table (frontend merges epid_code instead)
 *
 * The mirror is additive only — it creates or syncs the master row by name
 * but never deactivates/deletes it, so existing EPs keep their references.
 */
class EpidCodeSyncService
{
    public function sync(EpidCode $code): void
    {
        switch ($code->category) {
            case EpidCode::CATEGORY_DISTRICT:
                $this->mirror(District::class, $code);
                break;
            case EpidCode::CATEGORY_UPAZILA:
                $this->mirror(Upazila::class, $code, ['name', 'district_id'], ['district_id' => $code->district_id]);
                break;
            case EpidCode::CATEGORY_TITLE:
                $this->mirror(LandStatus::class, $code);
                break;
            default:
                break;
        }
    }

    /**
     * Create (or update) the master row linked to this code entry.
     *
     * Match priority:
     *   1. By `code` (+ optional extra where conditions) — stable sync link.
     *   2. By case-insensitive `name` — matches pre-seeded or legacy rows.
     *
     * When a record is found by `code` whose name **differs** from the EP ID
     * code's name, and that name already exists on a **different** record, the
     * name-matched record is used instead and its `code` is updated. This
     * avoids a `uk_district_name` / `uk_upazila_name` unique-constraint violation
     * that would occur if we tried to rename the code-matched record.
     *
     * @param  class-string  $model
     * @param  list<string>  $extraColumns  model fillable columns to copy, besides name
     * @param  array<string, mixed>  $extraWhere  additional where clauses for code lookup (e.g. district_id for upazilas)
     */
    private function mirror(string $model, EpidCode $code, array $extraColumns = [], array $extraWhere = []): void
    {
        $name = trim($code->name);

        $byCode = $model::query()
            ->where('code', $code->code)
            ->where($extraWhere)
            ->first();

        $byName = $model::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($byCode !== null && $byName !== null && $byCode->getKey() !== $byName->getKey()) {
            $record = $byName;
        } else {
            $record = $byCode ?? $byName;
        }

        if ($record === null) {
            $data = ['name' => $name, 'code' => $code->code, 'is_active' => (bool) $code->is_active];

            foreach ($extraColumns as $column) {
                $data[$column] = $code->{$column};
            }

            $model::create($data);

            return;
        }

        $changes = [];

        if ($record->name !== $name) {
            $changes['name'] = $name;
        }

        if ((string) $record->code !== (string) $code->code) {
            $changes['code'] = $code->code;
        }

        foreach ($extraColumns as $column) {
            if ((string) $record->{$column} !== (string) $code->{$column}) {
                $changes[$column] = $code->{$column};
            }
        }

        if ($changes !== []) {
            $record->update($changes);
        }
    }
}
