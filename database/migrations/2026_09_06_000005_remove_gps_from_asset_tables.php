<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove per-asset GPS columns (land, infrastructure, tree).
 *
 * GPS location now lives on the affected_person record itself — one GPS point
 * per EP — instead of being captured once per Land / Structure / Tree entry.
 * The `crop` table never had GPS columns. The affected_person GPS columns are
 * intentionally left untouched here.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['land', 'infrastructure', 'tree'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn(['gps_latitude', 'gps_longitude']);
            });
        }
    }

    public function down(): void
    {
        foreach (['land', 'infrastructure', 'tree'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->decimal('gps_latitude', 10, 7)->nullable();
                $blueprint->decimal('gps_longitude', 10, 7)->nullable();
            });
        }
    }
};
