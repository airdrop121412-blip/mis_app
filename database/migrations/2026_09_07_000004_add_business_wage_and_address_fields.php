<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business', function (Blueprint $table): void {
            $table->string('present_address', 500)->nullable()->after('business_name');
            $table->decimal('annual_income', 14, 2)->nullable()->after('capital_amount');
            $table->boolean('has_wage_labour')->default(false)->after('annual_income');
            $table->unsignedInteger('number_of_wage_labour')->nullable()->after('has_wage_labour');
        });
    }

    public function down(): void
    {
        Schema::table('business', function (Blueprint $table): void {
            $table->dropColumn(['present_address', 'annual_income', 'has_wage_labour', 'number_of_wage_labour']);
        });
    }
};
