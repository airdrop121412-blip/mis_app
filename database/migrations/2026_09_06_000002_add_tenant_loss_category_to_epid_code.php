<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add 'Tenant' to the loss_category EP ID codes (code 8).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('epid_code')->insertOrIgnore([
            'category' => 'loss_category',
            'name' => 'Tenant',
            'code' => '8',
            'sort_order' => 80,
            'is_active' => true,
            'remarks' => null,
        ]);
    }

    public function down(): void
    {
        DB::table('epid_code')
            ->where('category', 'loss_category')
            ->where('name', 'Tenant')
            ->delete();
    }
};
