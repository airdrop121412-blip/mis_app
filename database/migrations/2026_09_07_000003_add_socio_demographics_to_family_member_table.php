<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('family_member', function (Blueprint $table) {
            $table->string('marital_status', 50)->nullable()->after('gender_other');
            $table->string('education', 100)->nullable()->after('age');
            $table->string('education_other', 255)->nullable()->after('education');
            $table->boolean('does_work')->nullable()->after('occupation_other');
            $table->decimal('annual_income', 12, 2)->nullable()->after('does_work');
            $table->string('health_status', 150)->nullable()->after('annual_income');
            $table->string('disability_type', 100)->nullable()->after('health_status');
        });
    }

    public function down(): void
    {
        Schema::table('family_member', function (Blueprint $table) {
            $table->dropColumn([
                'marital_status',
                'education',
                'education_other',
                'does_work',
                'annual_income',
                'health_status',
                'disability_type',
            ]);
        });
    }
};
