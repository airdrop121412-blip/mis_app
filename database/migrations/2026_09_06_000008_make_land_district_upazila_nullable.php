<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make district_id / upazila_id nullable on land.
 *
 * District and Upazila are captured once at EP level; per-land they are
 * no longer required by the UI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('land', function (Blueprint $table) {
            $table->unsignedBigInteger('district_id')->nullable()->change();
            $table->unsignedBigInteger('upazila_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('land', function (Blueprint $table) {
            DB::table('land')->whereNull('district_id')->update(['district_id' => 0]);
            DB::table('land')->whereNull('upazila_id')->update(['upazila_id' => 0]);
            $table->unsignedBigInteger('district_id')->nullable(false)->change();
            $table->unsignedBigInteger('upazila_id')->nullable(false)->change();
        });
    }
};
