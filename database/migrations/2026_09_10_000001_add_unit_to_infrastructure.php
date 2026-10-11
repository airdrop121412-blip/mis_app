<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add measurement unit to the infrastructure (structure) table.
 *
 * unit: SFT | CFT | RFT | Number
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('infrastructure', function (Blueprint $table) {
            $table->string('unit', 20)->nullable()->after('structure_material_other');
        });
    }

    public function down(): void
    {
        Schema::table('infrastructure', function (Blueprint $table) {
            $table->dropColumn('unit');
        });
    }
};
