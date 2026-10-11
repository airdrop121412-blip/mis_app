<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant Information: free-text occupation when the tenant selects the
 * generic "Other" occupation — printed on the EPICAD / EP File instead of
 * the literal "Other".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->string('occupation_other')->nullable()->after('occupation_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenant', function (Blueprint $table) {
            $table->dropColumn('occupation_other');
        });
    }
};
