<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base Eloquent model for the authoritative MIS schema (Phase 2 DDL).
 *
 * Every one of the 64 tables shares these conventions:
 *   - surrogate PK named `<entity>_id` (BIGINT UNSIGNED AUTO_INCREMENT)
 *   - NO `created_at` / `updated_at` columns (explicit project requirement)
 *
 * Subclasses set `$table`, `$primaryKey`, `$fillable`, casts and relations.
 */
abstract class BaseModel extends Model
{
    /**
     * No table in the authoritative schema uses Laravel's timestamp columns.
     */
    public $timestamps = false;

    /**
     * All primary keys are BIGINT UNSIGNED (integer keys).
     */
    protected $keyType = 'int';

    /**
     * Whether the primary key is auto-incrementing (true everywhere here).
     */
    public $incrementing = true;
}
