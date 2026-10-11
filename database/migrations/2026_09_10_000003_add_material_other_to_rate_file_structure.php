<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add material_other to rate_file_structure.
 *
 * When the structure rate's material is "Other", the admin types the
 * actual material here and it is displayed in place of "Other".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_file_structure', function (Blueprint $table) {
            $table->string('material_other', 255)->nullable()->after('material');
        });
    }

    public function down(): void
    {
        Schema::table('rate_file_structure', function (Blueprint $table) {
            $table->dropColumn('material_other');
        });
    }
};
