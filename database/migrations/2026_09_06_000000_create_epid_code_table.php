<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * epid_code — configurable short codes used by the EP ID builder (EpIdService).
 *
 * Each row is one entry under a category, e.g. District → Bogura = 1.
 * Categories: district, upazila, project_phase, title, non_tile, loss_category.
 * Uniqueness is enforced per category and code.
 * For upazila codes, uniqueness is per (category, code, district_id)
 * — see migration 2026_09_22_000002.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('epid_code', function (Blueprint $table) {
            $table->bigIncrements('epid_code_id');
            $table->string('category', 40);
            $table->string('name', 150);
            $table->string('code', 20);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('remarks', 255)->nullable();
            $table->unique(['category', 'name']);
            $table->unique(['category', 'code']);
            $table->index(['category', 'is_active']);
        });

        // Default rows so Title / Non-Title and loss categories resolve without
        // further setup. District / Upazila / Project Phase codes are data that
        // admins enter per project (Admin → EP ID Codes).
        DB::table('epid_code')->insert([
            ['category' => 'title',        'name' => 'Title',     'code' => '1', 'sort_order' => 10, 'is_active' => true,  'remarks' => 'land_status = Title'],
            ['category' => 'non_tile',     'name' => 'Non-Title', 'code' => '2', 'sort_order' => 10, 'is_active' => true,  'remarks' => 'land_status = Non-Title'],
            ['category' => 'loss_category', 'name' => 'Land',                              'code' => '1', 'sort_order' => 10, 'is_active' => true, 'remarks' => null],
            ['category' => 'loss_category', 'name' => 'Structure',                         'code' => '2', 'sort_order' => 20, 'is_active' => true, 'remarks' => null],
            ['category' => 'loss_category', 'name' => 'Tree',                              'code' => '3', 'sort_order' => 30, 'is_active' => true, 'remarks' => null],
            ['category' => 'loss_category', 'name' => 'Land and Structure',                'code' => '4', 'sort_order' => 40, 'is_active' => true, 'remarks' => null],
            ['category' => 'loss_category', 'name' => 'Structure and Tree',                'code' => '5', 'sort_order' => 50, 'is_active' => true, 'remarks' => null],
            ['category' => 'loss_category', 'name' => 'Land and Tree',                     'code' => '6', 'sort_order' => 60, 'is_active' => true, 'remarks' => null],
            ['category' => 'loss_category', 'name' => 'Land, Structure and Tree',          'code' => '7', 'sort_order' => 70, 'is_active' => true, 'remarks' => null],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('epid_code');
    }
};
