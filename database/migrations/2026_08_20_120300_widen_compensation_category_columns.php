<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen category columns from legacy ENUM to VARCHAR so the 18 official
 * reference categories (CfL, RV_STRUCTURE, CAVHH, ...) can be stored.
 *
 * See docs/CATEGORY-RECONCILIATION-AUDIT.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ec_line_item', function (Blueprint $table) {
            $table->string('category', 50)->change();
        });

        Schema::table('compensation', function (Blueprint $table) {
            $table->string('category', 50)->change();
        });
    }

    public function down(): void
    {
        Schema::table('ec_line_item', function (Blueprint $table) {
            $table->enum('category', ['LAND', 'CROP', 'INFRASTRUCTURE', 'TREE', 'BUSINESS', 'WORKER', 'OTHER'])->change();
        });

        Schema::table('compensation', function (Blueprint $table) {
            $table->enum('category', ['LAND', 'CROP', 'INFRASTRUCTURE', 'TREE', 'BUSINESS', 'WORKER', 'OTHER'])->change();
        });
    }
};
