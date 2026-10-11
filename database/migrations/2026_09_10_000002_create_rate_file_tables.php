<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rate File tables (Admin → Rate Management).
 *
 * These power EC File compensation calculations. Each type is isolated in its
 * own table so the EC File engine can look up a rate by a natural key:
 *   - Land      : mouza + land_category
 *   - Structure : district + upazila + material + usage_type + nature + unit
 *   - Tree      : district + upazila + tree_type + size
 *
 * Schema follows the authoritative MIS conventions (BaseModel): an
 * `<entity>_id` surrogate PK and no Laravel timestamps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_file_land', function (Blueprint $table) {
            $table->bigIncrements('rate_file_land_id');
            $table->string('mouza', 255);
            $table->string('land_category', 255);
            $table->decimal('dc_office_rate', 14, 2)->default(0);
            $table->decimal('pavc_rate', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);

            $table->index(['mouza', 'land_category']);
        });

        Schema::create('rate_file_structure', function (Blueprint $table) {
            $table->bigIncrements('rate_file_structure_id');
            $table->unsignedBigInteger('district_id')->nullable();
            $table->unsignedBigInteger('upazila_id')->nullable();
            $table->string('material', 50);
            $table->string('usage_type', 255);
            $table->string('nature', 30);
            $table->string('unit', 20);
            $table->decimal('rate', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);

            $table->index(['district_id', 'upazila_id', 'material']);
        });

        Schema::create('rate_file_tree', function (Blueprint $table) {
            $table->bigIncrements('rate_file_tree_id');
            $table->unsignedBigInteger('district_id')->nullable();
            $table->unsignedBigInteger('upazila_id')->nullable();
            $table->string('tree_type', 255);
            $table->string('size', 20);
            $table->decimal('rate', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);

            $table->index(['district_id', 'upazila_id', 'tree_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_file_tree');
        Schema::dropIfExists('rate_file_structure');
        Schema::dropIfExists('rate_file_land');
    }
};
