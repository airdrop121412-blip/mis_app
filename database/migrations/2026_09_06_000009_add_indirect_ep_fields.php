<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Indirect affected persons (tenant, worker) get their own EP IDs.
 *
 * tenant gains the Tenant Information capture-form fields plus an
 * auto-generated ep_id and the parent EP's HH ID. worker gains an ep_id.
 * A "Wage Loss" loss_category code is seeded so an EP ID can be generated
 * for wage labourers (indirect persons, serial starts at #0001).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->string('ep_id')->nullable()->after('affected_person_id');
            $table->string('hh_id', 50)->nullable()->after('ep_id');
            $table->string('father_name', 255)->nullable()->after('name');
            $table->string('nid_number', 50)->nullable()->after('father_name');
            $table->string('mobile', 20)->nullable()->after('nid_number');
            $table->string('mouza', 255)->nullable()->after('mobile');
            $table->string('union_name', 255)->nullable()->after('mouza');
            $table->unsignedBigInteger('district_id')->nullable()->after('union_name');
            $table->unsignedBigInteger('upazila_id')->nullable()->after('district_id');
            $table->unsignedInteger('age')->nullable()->after('upazila_id');
            $table->unsignedBigInteger('gender_id')->nullable()->after('age');
            $table->unsignedBigInteger('infrastructure_id')->nullable()->after('gender_id');
        });

        Schema::table('worker', function (Blueprint $table) {
            $table->string('ep_id')->nullable()->after('affected_person_id');
        });

        DB::table('epid_code')->insertOrIgnore([
            'category' => 'loss_category',
            'name' => 'Wage Loss',
            'code' => '9',
            'sort_order' => 9,
            'is_active' => true,
            'remarks' => 'Indirect affected person — wage labour.',
        ]);
    }

    public function down(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->dropColumn([
                'ep_id', 'hh_id', 'father_name', 'nid_number', 'mobile', 'mouza',
                'union_name', 'district_id', 'upazila_id', 'age', 'gender_id', 'infrastructure_id',
            ]);
        });

        Schema::table('worker', function (Blueprint $table) {
            $table->dropColumn('ep_id');
        });

        DB::table('epid_code')
            ->where('category', 'loss_category')
            ->where('name', 'Wage Loss')
            ->delete();
    }
};
