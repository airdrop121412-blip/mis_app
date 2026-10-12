<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rate lookup tests — GET /api/v1/rates/{type}.
 *
 * Exposes the active rate masters (land/tree/infrastructure/crop) so the
 * Entry/Assessment frontend can populate assessment rate dropdowns.
 */
class RateTest extends TestCase
{
    use DatabaseTransactions;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $userId = DB::table('users')->insertGetId([
            'username' => 'rate_admin_'.Str::random(8),
            'full_name' => 'Rate Admin',
            'password_hash' => bcrypt('AdminPass@123'),
            'is_active' => 1,
        ]);
        DB::table('user_role')->insert([
            'user_id' => $userId,
            'role_id' => 1,
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

    public function test_unknown_rate_type_returns_404(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/rates/unknown');

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    public function test_land_rates_returns_active_rates_with_labels(): void
    {
        $landTypeId = DB::table('land_type')->value('land_type_id');
        $landUseTypeId = DB::table('land_use_type')->value('land_use_type_id');
        $unitId = DB::table('unit')->value('unit_id');

        DB::table('land_rate')->insert([
            'land_type_id' => $landTypeId,
            'land_use_type_id' => $landUseTypeId,
            'unit_id' => $unitId,
            'rate_per_unit' => '500.00',
            'is_active' => 1,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/rates/land');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'rate_id',
                    'rate_type',
                    'land_type_id',
                    'land_type',
                    'land_use_type_id',
                    'land_use_type',
                    'unit_id',
                    'unit',
                    'rate_per_unit',
                ],
            ],
        ]);
        $response->assertJsonPath('data.0.rate_type', 'land');
        $response->assertJsonPath('data.0.rate_per_unit', '500.00');
    }

    public function test_land_rates_filters_by_land_type(): void
    {
        $landTypeId = DB::table('land_type')->value('land_type_id');
        $landUseTypeId = DB::table('land_use_type')->value('land_use_type_id');
        $unitId = DB::table('unit')->value('unit_id');

        DB::table('land_rate')->insert([
            'land_type_id' => $landTypeId,
            'land_use_type_id' => $landUseTypeId,
            'unit_id' => $unitId,
            'rate_per_unit' => '500.00',
            'is_active' => 1,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson("/api/v1/rates/land?land_type_id={$landTypeId}");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_tree_rates_returns_active_rates(): void
    {
        $treeTypeId = DB::table('tree_type')->value('tree_type_id');
        $ageCategoryId = DB::table('tree_age_category')->value('tree_age_category_id');

        DB::table('tree_rate')->insert([
            'tree_type_id' => $treeTypeId,
            'tree_age_category_id' => $ageCategoryId,
            'rate_per_tree' => '200.00',
            'is_active' => 1,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/rates/tree');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'rate_id',
                    'rate_type',
                    'tree_type_id',
                    'tree_type',
                    'tree_age_category_id',
                    'tree_age_category',
                    'rate_per_unit',
                ],
            ],
        ]);
        $response->assertJsonPath('data.0.rate_type', 'tree');
        $response->assertJsonPath('data.0.rate_per_unit', '200.00');
    }

    public function test_infrastructure_rates_returns_active_rates(): void
    {
        $infraTypeId = DB::table('infrastructure_type')->value('infrastructure_type_id');
        $usageTypeId = DB::table('usage_type')->value('usage_type_id');

        DB::table('infrastructure_rate')->insert([
            'infrastructure_type_id' => $infraTypeId,
            'usage_type_id' => $usageTypeId,
            'rate_per_sqft' => '45.00',
            'is_active' => 1,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/rates/infrastructure');

        $response->assertOk();
        $response->assertJsonPath('data.0.rate_type', 'infrastructure');
        $response->assertJsonPath('data.0.rate_per_unit', '45.00');
    }

    public function test_crop_rates_returns_active_rates(): void
    {
        $cropTypeId = DB::table('crop_type')->value('crop_type_id');
        $cropSeasonId = DB::table('crop_season')->value('crop_season_id');
        $unitId = DB::table('unit')->value('unit_id');

        DB::table('crop_rate')->insert([
            'crop_type_id' => $cropTypeId,
            'crop_season_id' => $cropSeasonId,
            'unit_id' => $unitId,
            'rate_per_unit' => '300.00',
            'is_active' => 1,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/rates/crop');

        $response->assertOk();
        $response->assertJsonPath('data.0.rate_type', 'crop');
        $response->assertJsonPath('data.0.rate_per_unit', '300.00');
    }

    public function test_inactive_rates_are_excluded(): void
    {
        $landTypeId = DB::table('land_type')->value('land_type_id');
        $landUseTypeId = DB::table('land_use_type')->value('land_use_type_id');
        $unitId = DB::table('unit')->value('unit_id');

        DB::table('land_rate')->insert([
            'land_type_id' => $landTypeId,
            'land_use_type_id' => $landUseTypeId,
            'unit_id' => $unitId,
            'rate_per_unit' => '999.00',
            'is_active' => 0,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/rates/land');

        $response->assertOk();
        $response->assertJsonCount(0, 'data');
    }
}
