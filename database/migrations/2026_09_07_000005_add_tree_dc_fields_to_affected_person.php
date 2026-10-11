<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affected_person', function (Blueprint $table): void {
            $table->decimal('dc_tree_compensation', 14, 2)->nullable()->after('cheque_date');
            $table->string('dc_tree_cheque_no', 50)->nullable()->after('dc_tree_compensation');
            $table->date('dc_tree_cheque_date')->nullable()->after('dc_tree_cheque_no');
        });
    }

    public function down(): void
    {
        Schema::table('affected_person', function (Blueprint $table): void {
            $table->dropColumn(['dc_tree_compensation', 'dc_tree_cheque_no', 'dc_tree_cheque_date']);
        });
    }
};
