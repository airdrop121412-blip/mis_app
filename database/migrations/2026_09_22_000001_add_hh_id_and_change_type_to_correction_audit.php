<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin correction workflow (Approved EP → Correction tab).
 *
 * - `hh_id` is denormalized at write time because a correction of type DELETE
 *   permanently removes the EP graph, so the HH ID can no longer be joined.
 * - `change_type` distinguishes an EDIT correction from a DELETE correction so
 *   the Correction History page can show the cause and the action clearly.
 *
 * The table stays append-only; these columns only widen each audit row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('correction_audit', function (Blueprint $table): void {
            $table->string('hh_id', 50)->nullable()->after('ep_id')->index();
            $table->string('change_type', 20)->default('EDIT')->after('field_name')->index();
        });
    }

    public function down(): void
    {
        Schema::table('correction_audit', function (Blueprint $table): void {
            $table->dropIndex(['hh_id']);
            $table->dropIndex(['change_type']);
            $table->dropColumn(['hh_id', 'change_type']);
        });
    }
};
