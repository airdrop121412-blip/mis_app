<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add name and *_other fields to family_member table.
 *
 * - name: the family member's name (was missing from the table)
 * - relationship_other: manual text when Relationship = Other
 * - gender_other: manual text when Gender = Other
 * - occupation_other: manual text when Occupation = Other
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('family_member', function (Blueprint $table) {
            $table->string('name', 150)->nullable()->after('affected_person_id');
            $table->string('relationship_other', 255)->nullable()->after('relationship_id');
            $table->string('gender_other', 255)->nullable()->after('gender_id');
            $table->string('occupation_other', 255)->nullable()->after('occupation_id');
        });
    }

    public function down(): void
    {
        Schema::table('family_member', function (Blueprint $table) {
            $table->dropColumn(['name', 'relationship_other', 'gender_other', 'occupation_other']);
        });
    }
};
