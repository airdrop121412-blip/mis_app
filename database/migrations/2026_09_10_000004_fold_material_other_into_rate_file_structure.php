<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fold material_other into rate_file_structure.material.
 *
 * The Structure Rate material now stores the actual material text directly
 * (predefined values and any custom "Other" text from the Entry forms).
 * Existing "Other" rows are migrated to their typed description, then the
 * material_other column is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('rate_file_structure')
            ->where('material', 'Other')
            ->whereNotNull('material_other')
            ->where('material_other', '!=', '')
            ->get(['rate_file_structure_id', 'material_other'])
            ->each(function (stdClass $row) {
                DB::table('rate_file_structure')
                    ->where('rate_file_structure_id', $row->rate_file_structure_id)
                    ->update(['material' => trim((string) $row->material_other)]);
            });

        Schema::table('rate_file_structure', function (Blueprint $table) {
            $table->dropColumn('material_other');
        });
    }

    public function down(): void
    {
        Schema::table('rate_file_structure', function (Blueprint $table) {
            $table->string('material_other', 255)->nullable()->after('material');
        });
    }
};
