<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add structure nature + build material to the infrastructure (structure) table.
 *
 * structure_nature:        Primary | Secondary
 * structure_material:      Pacca | Semi Pacca | Tin Made | Katcha | Thatched | Other
 * structure_material_other: free text when material is Other
 *
 * DC compensation stays EP-level on affected_person (dc_compensation /
 * cheque_no / cheque_date) — it is a single total per EP, not per structure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('infrastructure', function (Blueprint $table) {
            $table->string('structure_nature', 20)->nullable()->after('usage_type_other');
            $table->string('structure_material', 50)->nullable()->after('structure_nature');
            $table->string('structure_material_other', 255)->nullable()->after('structure_material');
        });
    }

    public function down(): void
    {
        Schema::table('infrastructure', function (Blueprint $table) {
            $table->dropColumn(['structure_nature', 'structure_material', 'structure_material_other']);
        });
    }
};
