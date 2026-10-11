<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make EP ID Codes, Rate File, and Grant & Assistance project-scoped.
 *
 * Adds project_id to:
 *   - epid_code
 *   - rate_file_land
 *   - rate_file_structure
 *   - rate_file_tree
 *   - grant_assistance
 *   - grant_assistance_rate
 *
 * project_id is nullable so existing data is not lost; existing rows
 * will have project_id = NULL until a project selector is used.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── epid_code ────────────────────────────────
        if (! Schema::hasColumn('epid_code', 'project_id')) {
            Schema::table('epid_code', function (Blueprint $table) {
                $table->unsignedBigInteger('project_id')->nullable()->after('epid_code_id');
            });
        }

        // Drop old global unique constraints if they exist, add project-scoped ones.
        $this->dropIndexIfExists('epid_code', 'epid_code_category_code_unique');
        $this->dropIndexIfExists('epid_code', 'epid_code_category_name_unique');
        $this->dropIndexIfExists('epid_code', 'epid_code_category_code_district_id_unique');

        Schema::table('epid_code', function (Blueprint $table) {
            $table->unique(['project_id', 'category', 'name']);
            $table->unique(['project_id', 'category', 'code']);
            $table->index(['project_id', 'category', 'is_active']);
        });

        // ── rate_file_land ───────────────────────────
        if (! Schema::hasColumn('rate_file_land', 'project_id')) {
            Schema::table('rate_file_land', function (Blueprint $table) {
                $table->unsignedBigInteger('project_id')->nullable()->after('rate_file_land_id');
            });
        }

        // ── rate_file_structure ──────────────────────
        if (! Schema::hasColumn('rate_file_structure', 'project_id')) {
            Schema::table('rate_file_structure', function (Blueprint $table) {
                $table->unsignedBigInteger('project_id')->nullable()->after('rate_file_structure_id');
            });
        }

        // ── rate_file_tree ───────────────────────────
        if (! Schema::hasColumn('rate_file_tree', 'project_id')) {
            Schema::table('rate_file_tree', function (Blueprint $table) {
                $table->unsignedBigInteger('project_id')->nullable()->after('rate_file_tree_id');
            });
        }

        // ── grant_assistance ─────────────────────────
        if (! Schema::hasColumn('grant_assistance', 'project_id')) {
            Schema::table('grant_assistance', function (Blueprint $table) {
                $table->unsignedBigInteger('project_id')->nullable()->after('grant_assistance_id');
            });
        }

        // Update unique constraint on grant_code to be project-scoped.
        $this->dropIndexIfExists('grant_assistance', 'grant_assistance_grant_code_unique');
        Schema::table('grant_assistance', function (Blueprint $table) {
            $table->unique(['project_id', 'grant_code']);
        });

        // ── grant_assistance_rate ────────────────────
        if (! Schema::hasColumn('grant_assistance_rate', 'project_id')) {
            Schema::table('grant_assistance_rate', function (Blueprint $table) {
                $table->unsignedBigInteger('project_id')->nullable()->after('grant_assistance_rate_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('grant_assistance_rate', function (Blueprint $table) {
            $table->dropColumn('project_id');
        });

        $this->dropIndexIfExists('grant_assistance', 'grant_assistance_project_grant_code_unique');
        Schema::table('grant_assistance', function (Blueprint $table) {
            $table->unique('grant_code');
            $table->dropColumn('project_id');
        });

        Schema::table('rate_file_tree', function (Blueprint $table) {
            $table->dropColumn('project_id');
        });

        Schema::table('rate_file_structure', function (Blueprint $table) {
            $table->dropColumn('project_id');
        });

        Schema::table('rate_file_land', function (Blueprint $table) {
            $table->dropColumn('project_id');
        });

        $this->dropIndexIfExists('epid_code', 'epid_code_project_category_name_unique');
        $this->dropIndexIfExists('epid_code', 'epid_code_project_category_code_unique');
        $this->dropIndexIfExists('epid_code', 'epid_code_project_category_is_active_index');
        Schema::table('epid_code', function (Blueprint $table) {
            $table->unique(['category', 'name']);
            $table->unique(['category', 'code']);
            $table->index(['category', 'is_active']);
            $table->dropColumn('project_id');
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
