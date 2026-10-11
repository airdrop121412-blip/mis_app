<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow a second (additional-payment) compensation row per category.
     *
     * payment_cycle = 1 → original payment (locked once paid)
     * payment_cycle = 2 → additional payment for a PAID EP
     *
     * The unique key is widened to (ec_id, category, payment_cycle) so an
     * additional loss in an already-paid category (e.g. a newly added
     * structure's CfS/RV/HTG/HCG) can coexist with the locked original row.
     */
    public function up(): void
    {
        Schema::table('compensation', function (Blueprint $table): void {
            if (! Schema::hasColumn('compensation', 'payment_cycle')) {
                $table->unsignedTinyInteger('payment_cycle')->default(1)->after('status');
            }
            // Plain index on ec_id so the FK stays satisfied when the old
            // unique key (which currently serves that FK) is dropped.
            $table->index('ec_id', 'idx_compensation_ec_id');
            $table->dropUnique('uk_compensation_ec_category');
            $table->unique(['ec_id', 'category', 'payment_cycle'], 'uk_compensation_ec_category_cycle');
        });
    }

    public function down(): void
    {
        Schema::table('compensation', function (Blueprint $table): void {
            $table->dropUnique('uk_compensation_ec_category_cycle');
            $table->unique(['ec_id', 'category'], 'uk_compensation_ec_category');
            $table->dropIndex('idx_compensation_ec_id');
            if (Schema::hasColumn('compensation', 'payment_cycle')) {
                $table->dropColumn('payment_cycle');
            }
        });
    }
};
