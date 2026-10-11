<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * grant_assistance_rate — per-record multipliers for a Grant & Assistance
 * item scoped by geography + land category (used by DA).
 *
 * DA (Dislocation Allowance) is MULTIPLIER × total land (decimal), but the
 * multiplier may differ per district / upazila / land category. Each row
 * carries the multiplier for one scope:
 *   - district_id null = any district
 *   - upazila_id  null = any upazila (within the district)
 *   - land_category null = any land category
 * The EC engine prefers the most specific matching row for each land.
 *
 * Schema follows the authoritative MIS conventions (BaseModel): an
 * `<entity>_id` surrogate PK and no Laravel timestamps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grant_assistance_rate', function (Blueprint $table) {
            $table->bigIncrements('grant_assistance_rate_id');
            $table->unsignedBigInteger('grant_assistance_id');
            $table->unsignedBigInteger('district_id')->nullable();
            $table->unsignedBigInteger('upazila_id')->nullable();
            $table->string('land_category', 255)->nullable();
            $table->decimal('multiplier', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);

            $table->index(['grant_assistance_id', 'district_id', 'upazila_id'], 'gar_grant_district_upazila_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grant_assistance_rate');
    }
};
