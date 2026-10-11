<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase captured at EP-entry time (EPForm → "Project Phase"). Mirrors
 * project.phase so the EP ID builder can read it from the EP itself;
 * the service falls back to project.phase when the EP has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affected_person', function (Blueprint $table) {
            $table->string('phase', 50)->nullable()->after('upazila_id');
        });
    }

    public function down(): void
    {
        Schema::table('affected_person', function (Blueprint $table) {
            $table->dropColumn('phase');
        });
    }
};
