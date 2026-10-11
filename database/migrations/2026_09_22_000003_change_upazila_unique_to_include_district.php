<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change upazila unique constraint from (code)
 * to (code, district_id) so that the same upazila code
 * can exist in different districts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upazila', function (Blueprint $table) {
            $table->dropUnique('uk_upazila_code');
            $table->unique(['code', 'district_id']);
        });
    }

    public function down(): void
    {
        Schema::table('upazila', function (Blueprint $table) {
            $table->dropUnique('upazila_code_district_id_unique');
            $table->unique('code');
        });
    }
};
