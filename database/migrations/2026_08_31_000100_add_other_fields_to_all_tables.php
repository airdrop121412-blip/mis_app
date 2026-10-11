<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add *_other text fields to all tables that have master dropdowns with an "Other" option.
 *
 * Tables: affected_person, family_member, land, infrastructure, tree,
 *         business, worker, crop
 *
 * Fields added:
 *   affected_person: gender_other, religion_other, occupation_other
 *   family_member:   (already has name, relationship_other, gender_other, occupation_other)
 *   land:            land_type_other, ownership_type_other, land_use_type_other
 *   infrastructure:  infrastructure_type_other, usage_type_other
 *   tree:            tree_type_other
 *   business:        business_type_other
 *   worker:          gender_other, occupation_other, employment_type_other
 *   crop:            crop_type_other
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affected_person', function (Blueprint $table) {
            $table->string('gender_other', 255)->nullable()->after('gender_id');
            $table->string('religion_other', 255)->nullable()->after('religion_id');
            $table->string('occupation_other', 255)->nullable()->after('occupation_id');
        });

        Schema::table('family_member', function (Blueprint $table) {
            // name, relationship_other, gender_other, occupation_other already added
        });

        Schema::table('land', function (Blueprint $table) {
            $table->string('land_type_other', 255)->nullable()->after('land_type_id');
            $table->string('ownership_type_other', 255)->nullable()->after('ownership_type_id');
            $table->string('land_use_type_other', 255)->nullable()->after('land_use_type_id');
        });

        Schema::table('infrastructure', function (Blueprint $table) {
            $table->string('infrastructure_type_other', 255)->nullable()->after('infrastructure_type_id');
            $table->string('usage_type_other', 255)->nullable()->after('usage_type_id');
        });

        Schema::table('tree', function (Blueprint $table) {
            $table->string('tree_type_other', 255)->nullable()->after('tree_type_id');
        });

        Schema::table('business', function (Blueprint $table) {
            $table->string('business_type_other', 255)->nullable()->after('business_type_id');
        });

        Schema::table('worker', function (Blueprint $table) {
            $table->string('gender_other', 255)->nullable()->after('gender_id');
            $table->string('occupation_other', 255)->nullable()->after('occupation_id');
            $table->string('employment_type_other', 255)->nullable()->after('employment_type_id');
        });

        Schema::table('crop', function (Blueprint $table) {
            $table->string('crop_type_other', 255)->nullable()->after('crop_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('affected_person', function (Blueprint $table) {
            $table->dropColumn(['gender_other', 'religion_other', 'occupation_other']);
        });

        Schema::table('land', function (Blueprint $table) {
            $table->dropColumn(['land_type_other', 'ownership_type_other', 'land_use_type_other']);
        });

        Schema::table('infrastructure', function (Blueprint $table) {
            $table->dropColumn(['infrastructure_type_other', 'usage_type_other']);
        });

        Schema::table('tree', function (Blueprint $table) {
            $table->dropColumn(['tree_type_other']);
        });

        Schema::table('business', function (Blueprint $table) {
            $table->dropColumn(['business_type_other']);
        });

        Schema::table('worker', function (Blueprint $table) {
            $table->dropColumn(['gender_other', 'occupation_other', 'employment_type_other']);
        });

        Schema::table('crop', function (Blueprint $table) {
            $table->dropColumn(['crop_type_other']);
        });
    }
};
