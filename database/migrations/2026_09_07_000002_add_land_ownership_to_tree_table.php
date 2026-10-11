<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tree', function (Blueprint $table) {
            $table->string('land_ownership', 100)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('tree', function (Blueprint $table) {
            $table->dropColumn('land_ownership');
        });
    }
};
