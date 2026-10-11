<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affected_person', function (Blueprint $table): void {
            $table->enum('status', ['DRAFT', 'SUBMITTED', 'VERIFIED', 'APPROVED', 'PAID'])
                ->default('DRAFT')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('affected_person', function (Blueprint $table): void {
            $table->enum('status', ['DRAFT', 'SUBMITTED', 'VERIFIED', 'APPROVED'])
                ->default('DRAFT')
                ->change();
        });
    }
};
