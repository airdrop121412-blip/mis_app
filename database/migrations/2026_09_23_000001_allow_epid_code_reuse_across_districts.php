<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Allow the same EP ID code to be reused for upazilas in different districts.
 *
 * Migration 2026_09_22_000002 introduced UNIQUE(category, code, district_id)
 * for exactly this, but 2026_09_22_000004 (project scoping) replaced it with
 * UNIQUE(project_id, category, code) — silently dropping district_id again.
 *
 * This restores district-aware uniqueness on top of project scoping:
 *   UNIQUE(project_id, category, code, district_id)
 *
 * Note: rows with district_id = NULL (district/phase/title/loss categories)
 * are not DB-enforced because MySQL treats NULLs as distinct — application
 * validation (EpidCodeStoreRequest / EpidCodeUpdateRequest) enforces
 * per-(project, category) uniqueness for those categories.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->dropIndexIfExists('epid_code', 'epid_code_project_id_category_code_unique');

        Schema::table('epid_code', function (Blueprint $table) {
            $table->unique(['project_id', 'category', 'code', 'district_id']);
        });
    }

    public function down(): void
    {
        $this->dropIndexIfExists('epid_code', 'epid_code_project_id_category_code_district_id_unique');

        Schema::table('epid_code', function (Blueprint $table) {
            $table->unique(['project_id', 'category', 'code']);
        });
    }

    /**
     * Drop a MySQL index if it exists on the given table.
     */
    private function dropIndexIfExists(string $table, string $index): void
    {
        $exists = DB::select(
            'SELECT COUNT(*) AS cnt FROM information_schema.statistics WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [DB::getDatabaseName(), $table, $index]
        );

        if ($exists[0]->cnt > 0) {
            Schema::table($table, function (Blueprint $table) use ($index) {
                $table->dropIndex($index);
            });
        }
    }
};
