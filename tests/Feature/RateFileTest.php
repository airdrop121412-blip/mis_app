<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EC Rate File tests — CRUD on /api/v1/rate-file/{type}.
 *
 * The Rate File holds the reference rates (land / structure / tree) the
 * EC File engine uses for compensation. Read requires master.view; write
 * requires master.manage (admin only).
 */
class RateFileTest extends TestCase
{
    use DatabaseTransactions;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('rate_file_land')->delete();
        DB::table('rate_file_structure')->delete();
        DB::table('rate_file_tree')->delete();

        $userId = DB::table('users')->insertGetId([
            'username' => 'ratefile_admin_'.Str::random(8),
            'full_name' => 'Rate File Admin',
            'password_hash' => bcrypt('AdminPass@123'),
            'is_active' => 1,
        ]);
        DB::table('user_role')->insert([
            'user_id' => $userId,
            'role_id' => 1, // ADMIN
        ]);

        $this->token = Str::random(60);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $userId,
            'name' => 'test',
            'token' => hash('sha256', $this->token),
            'abilities' => json_encode(['*']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_unknown_rate_file_type_returns_404(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/rate-file/unknown');

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    public function test_admin_can_create_and_list_land_rate(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/rate-file/land', [
                'mouza' => 'Paschim Para',
                'land_category' => 'Other',
                'dc_office_rate' => '500.00',
                'pavc_rate' => '450.00',
                'is_active' => true,
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'data' => ['id', 'mouza', 'land_category', 'dc_office_rate', 'pavc_rate', 'is_active'],
        ]);
        $response->assertJsonPath('data.land_category', 'Other');
        $response->assertJsonPath('data.dc_office_rate', '500.00');

        $id = $response->json('data.id');

        $list = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/rate-file/land');

        $list->assertOk();
        $list->assertJsonPath('success', true);
        $list->assertJsonCount(1, 'data');
        $list->assertJsonPath('data.0.id', $id);
        $list->assertJsonPath('data.0.pavc_rate', '450.00');
    }

    public function test_admin_can_update_and_delete_structure_rate(): void
    {
        $districtId = DB::table('district')->value('district_id');
        $upazilaId = DB::table('upazila')->where('district_id', $districtId)->value('upazila_id');

        $create = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/rate-file/structure', [
                'district_id' => $districtId,
                'upazila_id' => $upazilaId,
                'material' => 'Pacca',
                'usage_type' => 'Residential',
                'nature' => 'Primary',
                'unit' => 'SFT',
                'rate' => '1250.00',
                'is_active' => true,
            ]);

        $create->assertStatus(201);
        $id = $create->json('data.id');

        $list = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/rate-file/structure');

        $list->assertOk();
        $list->assertJsonPath('data.0.id', $id);
        $list->assertJsonStructure([
            'data' => ['0' => ['id', 'district_id', 'district', 'upazila_id', 'upazila', 'material', 'usage_type', 'nature', 'unit', 'rate', 'is_active']],
        ]);
        $list->assertJsonPath('data.0.district', DB::table('district')->where('district_id', $districtId)->value('name'));
        $list->assertJsonPath('data.0.rate', '1250.00');

        $update = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson("/api/v1/rate-file/structure/{$id}", [
                'district_id' => $districtId,
                'upazila_id' => $upazilaId,
                'material' => 'Semi Pacca',
                'usage_type' => 'Residential',
                'nature' => 'Secondary',
                'unit' => 'SFT',
                'rate' => '900.00',
                'is_active' => false,
            ]);

        $update->assertOk();
        $update->assertJsonPath('data.rate', '900.00');
        $update->assertJsonPath('data.material', 'Semi Pacca');
        $update->assertJsonPath('data.is_active', false);

        $delete = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->deleteJson("/api/v1/rate-file/structure/{$id}");

        $delete->assertOk();

        $after = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/rate-file/structure');

        $after->assertJsonCount(0, 'data');
    }

    public function test_material_options_includes_custom_entry_materials(): void
    {
        $affectedPersonId = DB::table('affected_person')->insertGetId([
            'project_id' => '1',
            'ep_id' => 'EP'.rand(1000, 9999),
            'nid_number' => (string) rand(1000000000, 9999999999),
            'mobile' => '017'.rand(10000000, 99999999),
            'name' => 'Rate File Person',
            'father_name' => 'Father',
            'gender_id' => 1,
            'district_id' => 1,
            'upazila_id' => 1,
            'land_status_id' => 1,
            'rc_type_id' => 1,
            'monthly_income' => '5000.00',
            'monthly_expense' => '3000.00',
            'status' => 'VERIFIED',
            'is_household_head' => 1,
        ]);

        $infraTypeId = DB::table('infrastructure_type')->value('infrastructure_type_id');
        $usageTypeId = DB::table('usage_type')->value('usage_type_id');

        DB::table('infrastructure')->insert([
            [
                'affected_person_id' => $affectedPersonId,
                'infrastructure_type_id' => $infraTypeId,
                'usage_type_id' => $usageTypeId,
                'structure_material' => 'Other',
                'structure_material_other' => '  Bamboo  ',
                'area_sqft' => 120.00,
            ],
            [
                'affected_person_id' => $affectedPersonId,
                'infrastructure_type_id' => $infraTypeId,
                'usage_type_id' => $usageTypeId,
                'structure_material' => 'Other',
                'structure_material_other' => 'Bamboo',
                'area_sqft' => 90.00,
            ],
            [
                'affected_person_id' => $affectedPersonId,
                'infrastructure_type_id' => $infraTypeId,
                'usage_type_id' => $usageTypeId,
                'structure_material' => 'Pacca',
                'structure_material_other' => 'Should Not Appear',
                'area_sqft' => 200.00,
            ],
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/rate-file/material-options');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $values = collect($response->json('data'));
        $this->assertContains('Bamboo', $values->all());
        $this->assertCount(1, $values->filter(static fn (string $v): bool => $v === 'Bamboo'));
        $this->assertNotContains('Should Not Appear', $values->all());
        $this->assertNotContains('  Bamboo  ', $values->all());
    }

    public function test_options_endpoints_collect_custom_entry_values(): void
    {
        $affectedPersonId = DB::table('affected_person')->insertGetId([
            'project_id' => '1',
            'ep_id' => 'EP'.rand(1000, 9999),
            'nid_number' => (string) rand(1000000000, 9999999999),
            'mobile' => '017'.rand(10000000, 99999999),
            'name' => 'Rate File Options Person',
            'father_name' => 'Father',
            'gender_id' => 1,
            'district_id' => 1,
            'upazila_id' => 1,
            'land_status_id' => 1,
            'rc_type_id' => 1,
            'monthly_income' => '5000.00',
            'monthly_expense' => '3000.00',
            'status' => 'VERIFIED',
            'is_household_head' => 1,
        ]);

        $infraTypeId = DB::table('infrastructure_type')->value('infrastructure_type_id');
        $usageTypeOtherId = DB::table('usage_type')->where('name', 'Other')->value('usage_type_id');

        DB::table('infrastructure')->insert([
            [
                'affected_person_id' => $affectedPersonId,
                'infrastructure_type_id' => $infraTypeId,
                'usage_type_id' => $usageTypeOtherId,
                'usage_type_other' => '  Mosjid  ',
                'area_sqft' => 120.00,
            ],
            [
                'affected_person_id' => $affectedPersonId,
                'infrastructure_type_id' => $infraTypeId,
                'usage_type_id' => $usageTypeOtherId,
                'usage_type_other' => 'Mosjid',
                'area_sqft' => 90.00,
            ],
            [
                'affected_person_id' => $affectedPersonId,
                'infrastructure_type_id' => $infraTypeId,
                'usage_type_id' => DB::table('usage_type')->where('name', 'Residential')->value('usage_type_id'),
                'usage_type_other' => null,
                'area_sqft' => 200.00,
            ],
        ]);

        $landStatusId = DB::table('land_status')->value('land_status_id');
        $ownershipTypeId = DB::table('ownership_type')->value('ownership_type_id');
        $districtId = DB::table('district')->value('district_id');
        $upazilaId = DB::table('upazila')->value('upazila_id');
        $unitId = DB::table('unit')->value('unit_id');
        $landUseTypeId = DB::table('land_use_type')->value('land_use_type_id');

        DB::table('land')->insert([
            [
                'affected_person_id' => $affectedPersonId,
                'land_type_id' => DB::table('land_type')->where('name', 'Other')->value('land_type_id'),
                'land_type_other' => '  Char Bhumi  ',
                'land_status_id' => $landStatusId,
                'ownership_type_id' => $ownershipTypeId,
                'district_id' => $districtId,
                'upazila_id' => $upazilaId,
                'mouza' => '  Paschim Para  ',
                'land_area' => '10.0000',
                'land_area_unit_id' => $unitId,
                'land_use_type_id' => $landUseTypeId,
            ],
            [
                'affected_person_id' => $affectedPersonId,
                'land_type_id' => DB::table('land_type')->where('name', 'Other')->value('land_type_id'),
                'land_type_other' => 'Char Bhumi',
                'land_status_id' => $landStatusId,
                'ownership_type_id' => $ownershipTypeId,
                'district_id' => $districtId,
                'upazila_id' => $upazilaId,
                'mouza' => 'Paschim Para',
                'land_area' => '5.0000',
                'land_area_unit_id' => $unitId,
                'land_use_type_id' => $landUseTypeId,
            ],
        ]);

        $usage = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/rate-file/usage-type-options');
        $usage->assertOk();
        $usage->assertJsonPath('success', true);
        $usageValues = collect($usage->json('data'));
        $this->assertContains('Mosjid', $usageValues->all());
        $this->assertCount(1, $usageValues->filter(static fn (string $v): bool => $v === 'Mosjid'));
        $this->assertNotContains('  Mosjid  ', $usageValues->all());

        $mouza = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/rate-file/mouza-options');
        $mouza->assertOk();
        $mouzaValues = collect($mouza->json('data'));
        $this->assertContains('Paschim Para', $mouzaValues->all());
        $this->assertNotContains('  Paschim Para  ', $mouzaValues->all());

        $category = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/rate-file/land-category-options');
        $category->assertOk();
        $categoryValues = collect($category->json('data'));
        $this->assertContains('Char Bhumi', $categoryValues->all());
        $this->assertCount(1, $categoryValues->filter(static fn (string $v): bool => $v === 'Char Bhumi'));
        $this->assertNotContains('  Char Bhumi  ', $categoryValues->all());
    }

    public function test_material_options_is_open_to_master_view(): void
    {
        $user = User::factory()->create([
            'username' => 'ratefile_view_'.fake()->unique()->numerify('#####'),
            'password_hash' => bcrypt('ViewPass@123'),
        ]);
        DB::table('user_role')->insert([
            'user_id' => $user->user_id,
            'role_id' => 2, // OPERATOR — has master.view but not master.manage
        ]);

        $viewToken = Str::random(60);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->user_id,
            'name' => 'test',
            'token' => hash('sha256', $viewToken),
            'abilities' => json_encode(['*']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $read = $this->withHeader('Authorization', 'Bearer '.$viewToken)
            ->getJson('/api/v1/rate-file/material-options');
        $read->assertOk();

        $write = $this->withHeader('Authorization', 'Bearer '.$viewToken)
            ->postJson('/api/v1/rate-file/structure', [
                'material' => 'Bamboo',
                'usage_type' => 'Residential',
                'nature' => 'Primary',
                'unit' => 'SFT',
                'rate' => '300.00',
            ]);
        $write->assertStatus(403);
    }

    public function test_admin_can_create_list_tree_rate(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/rate-file/tree', [
                'tree_type' => 'Mango',
                'size' => 'Large',
                'rate' => '2000.00',
                'is_active' => true,
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => ['id', 'district_id', 'district', 'upazila_id', 'upazila', 'tree_type', 'size', 'rate', 'is_active'],
        ]);
        $response->assertJsonPath('data.tree_type', 'Mango');
        $response->assertJsonPath('data.rate', '2000.00');

        $list = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/rate-file/tree');

        $list->assertOk();
        $list->assertJsonCount(1, 'data');
        $list->assertJsonPath('data.0.size', 'Large');
    }

    public function test_validation_rejects_bad_payload(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/rate-file/land', [
                'mouza' => '',
                'dc_office_rate' => '-5',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_user_without_role_is_forbidden_from_writing(): void
    {
        $user = User::factory()->create([
            'username' => 'ratefile_noperm_'.fake()->unique()->numerify('#####'),
            'password_hash' => bcrypt('TestPass@123'),
        ]);

        $noPermToken = Str::random(60);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->user_id,
            'name' => 'test',
            'token' => hash('sha256', $noPermToken),
            'abilities' => json_encode(['*']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $write = $this->withHeader('Authorization', 'Bearer '.$noPermToken)
            ->postJson('/api/v1/rate-file/land', [
                'mouza' => 'Blocked',
                'land_category' => 'Kachari',
                'dc_office_rate' => '100.00',
                'pavc_rate' => '90.00',
            ]);

        $write->assertStatus(403);
    }
}
