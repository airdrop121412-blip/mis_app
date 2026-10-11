<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the EP capture-form fields (from Capture.PNG) to affected_person.
 *
 * These are the additional personal-information inputs captured on the
 * paper EP form: age, family composition, mouza/union, category of loss,
 * ownership, DC compensation, cheque details, yearly income, vulnerability
 * flags (disabled/landless/tribal/business loss), displacement info,
 * tenant info, and stamp duty.
 *
 * All new columns are nullable or defaulted so existing rows are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affected_person', function (Blueprint $table) {
            // ── Demographics / family ─────────────────────────────
            $table->unsignedInteger('age')->nullable()->after('date_of_birth');
            $table->unsignedInteger('total_family_member')->nullable()->after('is_household_head');
            $table->unsignedInteger('male_member')->nullable()->after('total_family_member');
            $table->unsignedInteger('female_member')->nullable()->after('male_member');

            // ── Location detail ────────────────────────────────────
            $table->string('mouza', 150)->nullable()->after('upazila_id');
            $table->string('union_name', 150)->nullable()->after('mouza');

            // ── Loss / ownership / compensation ────────────────────
            $table->string('category_of_loss', 100)->nullable()->after('union_name');
            $table->string('ownership', 100)->nullable()->after('category_of_loss');
            $table->decimal('dc_compensation', 14, 2)->nullable()->after('ownership');
            $table->string('cheque_no', 50)->nullable()->after('dc_compensation');
            $table->date('cheque_date')->nullable()->after('cheque_no');
            $table->decimal('yearly_income', 14, 2)->nullable()->after('cheque_date');

            // ── Vulnerability flags ────────────────────────────────
            $table->boolean('is_disabled')->default(false)->after('yearly_income');
            $table->boolean('is_landless')->default(false)->after('is_disabled');
            $table->boolean('is_tribal')->default(false)->after('is_landless');
            $table->boolean('has_business_loss')->default(false)->after('is_tribal');

            // ── Displacement ───────────────────────────────────────
            $table->string('shifting_materials', 255)->nullable()->after('has_business_loss');
            $table->string('nature_of_displacement', 100)->nullable()->after('shifting_materials');
            $table->string('displacement_status', 100)->nullable()->after('nature_of_displacement');

            // ── Tenant / stamp duty ────────────────────────────────
            $table->boolean('is_tenant')->default(false)->after('displacement_status');
            $table->unsignedInteger('total_tenant')->nullable()->after('is_tenant');
            $table->decimal('stamp_duty', 14, 2)->nullable()->after('total_tenant');
            $table->boolean('no_displacement')->default(false)->after('stamp_duty');
        });
    }

    public function down(): void
    {
        Schema::table('affected_person', function (Blueprint $table) {
            $table->dropColumn([
                'age',
                'total_family_member',
                'male_member',
                'female_member',
                'mouza',
                'union_name',
                'category_of_loss',
                'ownership',
                'dc_compensation',
                'cheque_no',
                'cheque_date',
                'yearly_income',
                'is_disabled',
                'is_landless',
                'is_tribal',
                'has_business_loss',
                'shifting_materials',
                'nature_of_displacement',
                'displacement_status',
                'is_tenant',
                'total_tenant',
                'stamp_duty',
                'no_displacement',
            ]);
        });
    }
};
