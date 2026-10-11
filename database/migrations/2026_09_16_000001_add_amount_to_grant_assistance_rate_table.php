<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Grant & Assistance rate records previously only carried a per-scope
 * `multiplier` (used by DA). CfB (Compensation for Business Loss) is a
 * one-time grant that may differ from district to district, so each record
 * now also carries a per-district `amount` (one-time BDT amount).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grant_assistance_rate', function (Blueprint $table) {
            $table->decimal('amount', 14, 2)->default(0)->after('land_category');
        });
    }

    public function down(): void
    {
        Schema::table('grant_assistance_rate', function (Blueprint $table) {
            $table->dropColumn('amount');
        });
    }
};
