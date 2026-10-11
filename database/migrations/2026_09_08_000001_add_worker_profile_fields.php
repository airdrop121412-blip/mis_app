<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worker', function (Blueprint $table): void {
            $table->string('father_name', 150)->nullable()->after('name');
            $table->string('mobile', 20)->nullable()->after('father_name');
            $table->string('nid_number', 30)->nullable()->after('mobile');
            $table->string('present_address', 500)->nullable()->after('nid_number');
            $table->unsignedInteger('total_family_member')->nullable()->after('present_address');
            $table->unsignedInteger('duration_of_employment_month')->nullable()->after('total_family_member');
        });
    }

    public function down(): void
    {
        Schema::table('worker', function (Blueprint $table): void {
            $table->dropColumn([
                'father_name',
                'mobile',
                'nid_number',
                'present_address',
                'total_family_member',
                'duration_of_employment_month',
            ]);
        });
    }
};
