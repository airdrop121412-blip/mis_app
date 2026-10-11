<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * project.phase — survey/implementation phase used by the EP ID builder.
 * Maps to an entry in the epid_code table under category 'project_phase'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project', function (Blueprint $table) {
            $table->string('phase', 50)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('project', function (Blueprint $table) {
            $table->dropColumn('phase');
        });
    }
};
