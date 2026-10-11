<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wage labour (worker) location fields.
 *
 * Replaces the free-text present_address with the structured location a
 * worker's EP ID is derived from: mouza, union, district and upazila.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker', function (Blueprint $table): void {
            $table->string('mouza', 255)->nullable()->after('nid_number');
            $table->string('union_name', 255)->nullable()->after('mouza');
            $table->unsignedBigInteger('district_id')->nullable()->after('union_name');
            $table->unsignedBigInteger('upazila_id')->nullable()->after('district_id');
            $table->dropColumn('present_address');
        });
    }

    public function down(): void
    {
        Schema::table('worker', function (Blueprint $table): void {
            $table->string('present_address', 500)->nullable()->after('nid_number');
            $table->dropColumn(['mouza', 'union_name', 'district_id', 'upazila_id']);
        });
    }
};
