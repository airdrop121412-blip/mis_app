<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tree', function (Blueprint $table) {
            $table->string('name', 255)->nullable()->after('tree_type_other');
        });
    }

    public function down(): void
    {
        Schema::table('tree', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
