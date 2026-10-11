<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add the CfB (Compensation for Business Loss) Grant & Assistance item.
 * It is a one-time grant (FIXED) with optional per-district amount records,
 * so the EC File / Admin → Grant and Assistance page can configure a
 * district-wise Business Loss compensation.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('grant_assistance')->updateOrInsert(
            ['grant_code' => 'CfB'],
            [
                'grant_name' => 'Compensation for Business Loss',
                'calculation_type' => 'FIXED',
                'amount' => 0,
                'percentage' => 0,
                'percentage_base' => null,
                'category' => null,
                'multiplier' => 0,
                'is_active' => true,
            ],
        );
    }

    public function down(): void
    {
        DB::table('grant_assistance')->where('grant_code', 'CfB')->delete();
    }
};
