<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen correction_audit.project_id to match project.project_id.
 *
 * The original migration used unsignedTinyInteger (max 255), but the
 * authoritative project table uses bigint unsigned. Project IDs can
 * exceed 255, so this aligns the FK column type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('correction_audit', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->change();
        });
    }

    public function down(): void
    {
        Schema::table('correction_audit', function (Blueprint $table) {
            $table->unsignedTinyInteger('project_id')->change();
        });
    }
};
