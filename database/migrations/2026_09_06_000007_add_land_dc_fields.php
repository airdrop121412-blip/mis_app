<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add per-land DC compensation fields + LA case no to the land table.
 *
 * LA Case No:      free text (acquisition case reference).
 * DC Pay fields:   shown in the UI only when Land Status = Title.
 * District/Upazila are no longer captured per-land (EP-level only).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('land', function (Blueprint $table) {
            $table->string('la_case_no', 255)->nullable()->after('dag_number');
            $table->decimal('dc_pay_amount', 14, 2)->nullable()->after('la_case_no');
            $table->string('dc_cheque_no', 50)->nullable()->after('dc_pay_amount');
            $table->date('dc_pay_date')->nullable()->after('dc_cheque_no');
        });
    }

    public function down(): void
    {
        Schema::table('land', function (Blueprint $table) {
            $table->dropColumn(['la_case_no', 'dc_pay_amount', 'dc_cheque_no', 'dc_pay_date']);
        });
    }
};
