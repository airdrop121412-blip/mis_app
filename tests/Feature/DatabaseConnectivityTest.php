<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;

/**
 * PHASE 4A database-compatibility checks against the live authoritative
 * schema. These are read-only — they never modify seed data.
 */
class DatabaseConnectivityTest extends TestCase
{
    public function test_can_connect_to_mysql_database(): void
    {
        $pdo = DB::connection()->getPdo();

        $this->assertNotNull($pdo);
        $this->assertSame('mysql', DB::connection()->getDriverName());
    }

    public function test_schema_contains_all_64_tables(): void
    {
        $tables = DB::select('SHOW TABLES');
        $names = array_map(fn ($t) => array_values((array) $t)[0], $tables);

        $authoritative = [
            'district', 'upazila', 'village', 'religion', 'gender', 'occupation', 'relationship',
            'land_status', 'residential_commercial_type', 'tenant_type', 'land_type', 'ownership_type',
            'land_use_type', 'land_damage_type', 'crop_type', 'crop_season', 'tree_type', 'tree_age_category',
            'infrastructure_type', 'usage_type', 'business_type', 'business_category', 'business_ownership_type',
            'employment_type', 'unit', 'payment_method', 'location_source',
            'project', 'ep_sequence', 'users', 'role', 'permission', 'role_permission', 'user_role',
            'land_rate', 'crop_rate', 'tree_rate', 'infrastructure_rate',
            'affected_person', 'epicad', 'ec', 'family_member', 'land', 'crop', 'infrastructure', 'tree',
            'tenant', 'business', 'worker',
            'land_assessment', 'crop_assessment', 'infrastructure_assessment', 'tree_assessment',
            'business_loss', 'worker_assessment',
            'vulnerability_rule', 'vulnerability_rule_condition', 'allowance',
            'ec_line_item', 'compensation', 'voucher', 'voucher_compensation', 'payment', 'status_history',
            'epid_code', 'epicad_design',
            'tenant_infrastructure',
            'rate_file_land', 'rate_file_structure', 'rate_file_tree',
            'grant_assistance',
            'grant_assistance_rate',
        ];

        $this->assertCount(72, $authoritative, 'The authoritative contract defines 72 tables.');
        $this->assertSame(
            [],
            array_values(array_diff($authoritative, $names)),
            'Every authoritative Phase 2 table must still exist after Laravel migration.'
        );

        // Post-migration: Laravel/Sanctum + Phase 4B-9 correction_audit +
        // additional_payment_record (second-payment tracking).
        $this->assertCount(76, $names, 'Expected 72 authoritative + personal_access_tokens + migrations + correction_audit + additional_payment_record.');
        $this->assertContains('personal_access_tokens', $names);
        $this->assertContains('migrations', $names);
        $this->assertContains('correction_audit', $names);
        $this->assertContains('additional_payment_record', $names);
    }

    public function test_users_table_uses_user_id_and_password_hash(): void
    {
        $columns = DB::select('SHOW COLUMNS FROM users');

        $columnNames = array_column($columns, 'Field');

        $this->assertContains('user_id', $columnNames);
        $this->assertContains('password_hash', $columnNames);
        $this->assertNotContains('password', $columnNames);
    }

    public function test_rbac_tables_are_seeded_with_codes(): void
    {
        $roles = DB::table('role')->count();
        $permissions = DB::table('permission')->count();
        $rolePermissions = DB::table('role_permission')->count();
        $users = DB::table('users')->count();

        $this->assertGreaterThanOrEqual(6, $roles);
        $this->assertGreaterThanOrEqual(32, $permissions);
        $this->assertGreaterThanOrEqual(76, $rolePermissions);
        $this->assertGreaterThanOrEqual(1, $users);

        $adminRole = DB::table('role')->where('code', 'ADMIN')->first();
        $this->assertNotNull($adminRole, 'ADMIN role code must exist (seed contract).');
    }

    public function test_status_enum_columns_match_contract(): void
    {
        // affected_person.status must be DRAFT|SUBMITTED|VERIFIED|APPROVED.
        $row = DB::selectOne("SHOW COLUMNS FROM affected_person WHERE Field = 'status'");

        $this->assertNotNull($row, 'affected_person.status column must exist.');
        $this->assertStringContainsString('enum', (string) $row->Type);
        $this->assertStringContainsString("'DRAFT'", (string) $row->Type);
        $this->assertStringContainsString("'APPROVED'", (string) $row->Type);
    }
}
