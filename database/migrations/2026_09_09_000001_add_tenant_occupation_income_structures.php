<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant Information additions:
 *  - occupation_id + annual_income on the tenant record (a tenant rents a
 *    structure for business/residence and earns an income).
 *  - tenant_infrastructure pivot so a single tenant can rent MULTIPLE
 *    structures — each tenant EP File structure row is keyed by the
 *    Structure Sl No recorded here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->unsignedBigInteger('occupation_id')->nullable()->after('gender_id');
            $table->decimal('annual_income', 14, 2)->nullable()->after('occupation_id');
        });

        Schema::create('tenant_infrastructure', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('infrastructure_id');
            $table->primary(['tenant_id', 'infrastructure_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_infrastructure');

        Schema::table('tenant', function (Blueprint $table) {
            $table->dropColumn(['occupation_id', 'annual_income']);
        });
    }
};
