<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rename correction_audit.id → correction_audit_id.
 *
 * The original migration used $table->id() which created a column named
 * `id`, but the CorrectionAudit model declares $primaryKey =
 * 'correction_audit_id'. This aligns the live schema with the model and
 * the frontend contract (CorrectionRecord.correction_audit_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('correction_audit', function (Blueprint $table) {
            $table->renameColumn('id', 'correction_audit_id');
        });
    }

    public function down(): void
    {
        Schema::table('correction_audit', function (Blueprint $table) {
            $table->renameColumn('correction_audit_id', 'id');
        });
    }
};
