<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add optional district link for upazila EP ID codes so synced upazila
 * master rows can be attached to a district (Admin → EP ID Codes → Upazila).
 *
 * Plain nullable column (no FK) — the district master may be added after the
 * code, and EP IDs keep working even if a district row is later deactivated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epid_code', function (Blueprint $table) {
            $table->unsignedBigInteger('district_id')->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('epid_code', function (Blueprint $table) {
            $table->dropColumn('district_id');
        });
    }
};
