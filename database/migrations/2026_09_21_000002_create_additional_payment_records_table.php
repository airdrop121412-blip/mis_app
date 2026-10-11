<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track records entered from the additional-payment workflow without
     * changing any of the existing EP child tables. Missing rows deliberately
     * mean original (first-payment) records.
     */
    public function up(): void
    {
        Schema::create('additional_payment_record', function (Blueprint $table): void {
            $table->id('additional_payment_record_id');
            $table->foreignId('affected_person_id')
                ->constrained('affected_person', 'affected_person_id')
                ->cascadeOnDelete();
            $table->string('record_type', 80);
            $table->unsignedBigInteger('record_id');
            $table->foreignId('voucher_id')
                ->nullable()
                ->constrained('voucher', 'voucher_id')
                ->nullOnDelete();
            $table->unsignedTinyInteger('payment_cycle')->default(2);
            $table->timestamp('locked_at')->nullable();

            $table->unique(['record_type', 'record_id']);
            $table->index(['affected_person_id', 'voucher_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('additional_payment_record');
    }
};
