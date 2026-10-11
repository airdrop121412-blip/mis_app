<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add a free-text `designation` (job title) column to the users table.
 *
 * Surfaced in Admin → Users so each account can record the officer's
 * designation (e.g. "Entry Operator", "Project Director").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'designation')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('designation', 100)->nullable()->after('full_name');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'designation')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('designation');
        });
    }
};
