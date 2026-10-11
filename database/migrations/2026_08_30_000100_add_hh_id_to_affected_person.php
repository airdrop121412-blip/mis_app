<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add HH ID (household ID) to affected_person.
 *
 * The paper EP form captures a household identifier (HH ID) alongside the
 * EP ID. Nullable so existing rows are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affected_person', function (Blueprint $table) {
            $table->string('hh_id', 50)->nullable()->after('ep_id');
        });
    }

    public function down(): void
    {
        Schema::table('affected_person', function (Blueprint $table) {
            $table->dropColumn('hh_id');
        });
    }
};
