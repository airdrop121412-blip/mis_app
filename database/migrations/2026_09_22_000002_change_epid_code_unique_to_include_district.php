<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Change epid_code unique constraint from (category, code)
 * to (category, code, district_id) so that the same code
 * can be reused for upazila codes in different districts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epid_code', function (Blueprint $table) {
            $table->dropUnique('epid_code_category_code_unique');
            $table->unique(['category', 'code', 'district_id']);
        });
    }

    public function down(): void
    {
        Schema::table('epid_code', function (Blueprint $table) {
            $table->dropUnique('epid_code_category_code_district_id_unique');
            $table->unique(['category', 'code']);
        });
    }
};
