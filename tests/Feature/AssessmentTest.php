<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Assessment tests — all 6 types: Land, Crop, Infrastructure, Tree, Business Loss, Worker.
 *
 * Uses rate snapshot pattern. Master data is pre-seeded.
 */
class AssessmentTest extends TestCase
{
    use DatabaseTransactions;

    private string $token;

    private int $userId;

    private int $projectId;

    private int $epId;

    private int $districtId;

    private int $upazilaId;

    protected function setUp(): void
    {
        parent::setUp();

        // Admin user + token
        $this->userId = DB::table('users')->insertGetId([
            'username' => 'assess_admin_'.Str::random(8),
            'full_name' => 'Assessment Admin',
            'password_hash' => bcrypt('AdminPass@123'),
            'is_active' => 1,
        ]);
        DB::table('user_role')->insert([
            'user_id' => $this->userId,
            'role_id' => 1,
        ]);

        $this->token = Str::random(60);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $this->userId,
            'name' => 'test',
            'token' => hash('sha256', $this->token),
            'abilities' => json_encode(['*']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Project
        $this->projectId = DB::table('project')->insertGetId([
            'name' => 'Assessment Test Project',
            'project_code' => 'ATP-'.Str::random(5),
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'is_active' => 1,
        ]);

        // District + Upazila
        $this->districtId = DB::table('district')->insertGetId([
            'name' => 'Assess District '.Str::random(5),
            'code' => (string) rand(10, 99),
            'is_active' => 1,
        ]);
        $this->upazilaId = DB::table('upazila')->insertGetId([
            'name' => 'Assess Upazila '.Str::random(5),
            'code' => (string) rand(10, 99),
            'district_id' => $this->districtId,
            'is_active' => 1,
        ]);

        // EP (SUBMITTED)
        $this->epId = DB::table('affected_person')->insertGetId([
            'project_id' => $this->projectId,
            'ep_id' => 'ASS'.rand(1000, 9999),
            'name' => 'Assessment Person',
            'nid_number' => (string) rand(1000000000, 9999999999),
            'mobile' => '017'.rand(10000000, 99999999),
            'gender_id' => 1,
            'district_id' => $this->districtId,
            'upazila_id' => $this->upazilaId,
            'land_status_id' => 1,
            'rc_type_id' => 1,
            'monthly_income' => '5000.00',
            'monthly_expense' => '3000.00',
            'status' => 'SUBMITTED',
            'is_household_head' => 1,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // LAND ASSESSMENT
    // ═══════════════════════════════════════════════════════════════

    public function test_land_assessment_show_404(): void
    {
        $landId = $this->createLand();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson("/api/v1/ep/{$this->epId}/land/{$landId}/assessment");

        $response->assertStatus(404);
    }

    public function test_land_assessment_create(): void
    {
        $landId = $this->createLand();
        $rateId = $this->createLandRate();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/land/{$landId}/assessment", [
                'damage_type_id' => DB::table('land_damage_type')->value('land_damage_type_id'),
                'affected_area' => '10.5000',
                'unit_id' => DB::table('unit')->value('unit_id'),
                'rate_id' => $rateId,
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.land_id', $landId);
        // assessed_loss = 10.5 * 500.00 = 5250.00
        $response->assertJsonPath('data.assessed_loss', '5250.00');
    }

    public function test_land_assessment_duplicate_rejected(): void
    {
        $landId = $this->createLand();
        $rateId = $this->createLandRate();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/land/{$landId}/assessment", [
                'damage_type_id' => DB::table('land_damage_type')->value('land_damage_type_id'),
                'affected_area' => '10.0000',
                'unit_id' => DB::table('unit')->value('unit_id'),
                'rate_id' => $rateId,
            ])
            ->assertCreated();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/land/{$landId}/assessment", [
                'damage_type_id' => DB::table('land_damage_type')->value('land_damage_type_id'),
                'affected_area' => '20.0000',
                'unit_id' => DB::table('unit')->value('unit_id'),
                'rate_id' => $rateId,
            ]);

        $response->assertStatus(422);
    }

    public function test_land_assessment_update(): void
    {
        $landId = $this->createLand();
        $rateId = $this->createLandRate();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/land/{$landId}/assessment", [
                'damage_type_id' => DB::table('land_damage_type')->value('land_damage_type_id'),
                'affected_area' => '10.0000',
                'unit_id' => DB::table('unit')->value('unit_id'),
                'rate_id' => $rateId,
            ])
            ->assertCreated();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson("/api/v1/ep/{$this->epId}/land/{$landId}/assessment", [
                'affected_area' => '20.0000',
            ]);

        $response->assertOk();
        // 20.0 * 500.00 = 10000.00
        $response->assertJsonPath('data.assessed_loss', '10000.00');
    }

    public function test_land_assessment_delete(): void
    {
        $landId = $this->createLand();
        $rateId = $this->createLandRate();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/land/{$landId}/assessment", [
                'damage_type_id' => DB::table('land_damage_type')->value('land_damage_type_id'),
                'affected_area' => '10.0000',
                'unit_id' => DB::table('unit')->value('unit_id'),
                'rate_id' => $rateId,
            ])
            ->assertCreated();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->deleteJson("/api/v1/ep/{$this->epId}/land/{$landId}/assessment");

        $response->assertOk();
    }

    // ═══════════════════════════════════════════════════════════════
    // CROP ASSESSMENT
    // ═══════════════════════════════════════════════════════════════

    public function test_crop_assessment_show_404(): void
    {
        $landId = $this->createLand();
        $cropId = $this->createCrop($landId);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson("/api/v1/ep/{$this->epId}/land/{$landId}/crop/{$cropId}/assessment");

        $response->assertStatus(404);
    }

    public function test_crop_assessment_create(): void
    {
        $landId = $this->createLand();
        $cropId = $this->createCrop($landId);
        $rateId = $this->createCropRate();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/land/{$landId}/crop/{$cropId}/assessment", [
                'rate_id' => $rateId,
                'cultivated_area' => '5.0000',
                'damage_percentage' => '50.00',
            ]);

        $response->assertCreated();
        // assessed_loss = 5.0 * 300.00 * (50/100) = 750.00
        $response->assertJsonPath('data.assessed_loss', '750.00');
    }

    public function test_crop_assessment_duplicate_rejected(): void
    {
        $landId = $this->createLand();
        $cropId = $this->createCrop($landId);
        $rateId = $this->createCropRate();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/land/{$landId}/crop/{$cropId}/assessment", [
                'rate_id' => $rateId,
                'cultivated_area' => '5.0000',
                'damage_percentage' => '50.00',
            ])
            ->assertCreated();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/land/{$landId}/crop/{$cropId}/assessment", [
                'rate_id' => $rateId,
                'cultivated_area' => '10.0000',
                'damage_percentage' => '75.00',
            ]);

        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════
    // INFRASTRUCTURE ASSESSMENT
    // ═══════════════════════════════════════════════════════════════

    public function test_infrastructure_assessment_show_404(): void
    {
        $infraId = $this->createInfrastructure();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson("/api/v1/ep/{$this->epId}/infrastructure/{$infraId}/assessment");

        $response->assertStatus(404);
    }

    public function test_infrastructure_assessment_create(): void
    {
        $infraId = $this->createInfrastructure();
        $rateId = $this->createInfrastructureRate();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/infrastructure/{$infraId}/assessment", [
                'rate_id' => $rateId,
                'area_sqft' => '200.00',
                'damage_percentage' => '75.00',
            ]);

        $response->assertCreated();
        // assessed_loss = 200.0 * 45.00 * (75/100) = 6750.00
        $response->assertJsonPath('data.assessed_loss', '6750.00');
    }

    public function test_infrastructure_assessment_duplicate_rejected(): void
    {
        $infraId = $this->createInfrastructure();
        $rateId = $this->createInfrastructureRate();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/infrastructure/{$infraId}/assessment", [
                'rate_id' => $rateId,
                'area_sqft' => '200.00',
                'damage_percentage' => '75.00',
            ])
            ->assertCreated();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/infrastructure/{$infraId}/assessment", [
                'rate_id' => $rateId,
                'area_sqft' => '100.00',
                'damage_percentage' => '50.00',
            ]);

        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════
    // TREE ASSESSMENT
    // ═══════════════════════════════════════════════════════════════

    public function test_tree_assessment_show_404(): void
    {
        $treeId = $this->createTree();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson("/api/v1/ep/{$this->epId}/tree/{$treeId}/assessment");

        $response->assertStatus(404);
    }

    public function test_tree_assessment_create(): void
    {
        $treeId = $this->createTree();
        $rateId = $this->createTreeRate();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/tree/{$treeId}/assessment", [
                'rate_id' => $rateId,
                'quantity' => 10,
            ]);

        $response->assertCreated();
        // assessed_loss = 10 * 200.00 = 2000.00
        $response->assertJsonPath('data.assessed_loss', '2000.00');
    }

    public function test_tree_assessment_duplicate_rejected(): void
    {
        $treeId = $this->createTree();
        $rateId = $this->createTreeRate();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/tree/{$treeId}/assessment", [
                'rate_id' => $rateId,
                'quantity' => 10,
            ])
            ->assertCreated();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/tree/{$treeId}/assessment", [
                'rate_id' => $rateId,
                'quantity' => 20,
            ]);

        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════
    // BUSINESS LOSS
    // ═══════════════════════════════════════════════════════════════

    public function test_business_loss_show_404(): void
    {
        $bizId = $this->createBusiness();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson("/api/v1/ep/{$this->epId}/business/{$bizId}/loss");

        $response->assertStatus(404);
    }

    public function test_business_loss_create(): void
    {
        $bizId = $this->createBusiness();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/business/{$bizId}/loss", [
                'loss_amount' => '25000.00',
                'remarks' => 'Storm damage',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.loss_amount', '25000.00');
    }

    public function test_business_loss_duplicate_rejected(): void
    {
        $bizId = $this->createBusiness();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/business/{$bizId}/loss", [
                'loss_amount' => '25000.00',
            ])
            ->assertCreated();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/business/{$bizId}/loss", [
                'loss_amount' => '30000.00',
            ]);

        $response->assertStatus(422);
    }

    public function test_business_loss_update(): void
    {
        $bizId = $this->createBusiness();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/business/{$bizId}/loss", [
                'loss_amount' => '25000.00',
            ])
            ->assertCreated();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson("/api/v1/ep/{$this->epId}/business/{$bizId}/loss", [
                'loss_amount' => '35000.00',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.loss_amount', '35000.00');
    }

    public function test_business_loss_delete(): void
    {
        $bizId = $this->createBusiness();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/business/{$bizId}/loss", [
                'loss_amount' => '25000.00',
            ])
            ->assertCreated();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->deleteJson("/api/v1/ep/{$this->epId}/business/{$bizId}/loss");

        $response->assertOk();
    }

    // ═══════════════════════════════════════════════════════════════
    // WORKER ASSESSMENT
    // ═══════════════════════════════════════════════════════════════

    public function test_worker_assessment_show_404(): void
    {
        $workerId = $this->createWorker();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson("/api/v1/ep/{$this->epId}/worker/{$workerId}/assessment");

        $response->assertStatus(404);
    }

    public function test_worker_assessment_create(): void
    {
        $workerId = $this->createWorker();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/worker/{$workerId}/assessment", [
                'loss_amount' => '15000.00',
                'remarks' => 'Lost wages',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.loss_amount', '15000.00');
    }

    public function test_worker_assessment_duplicate_rejected(): void
    {
        $workerId = $this->createWorker();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/worker/{$workerId}/assessment", [
                'loss_amount' => '15000.00',
            ])
            ->assertCreated();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/worker/{$workerId}/assessment", [
                'loss_amount' => '20000.00',
            ]);

        $response->assertStatus(422);
    }

    public function test_worker_assessment_update(): void
    {
        $workerId = $this->createWorker();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/worker/{$workerId}/assessment", [
                'loss_amount' => '15000.00',
            ])
            ->assertCreated();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson("/api/v1/ep/{$this->epId}/worker/{$workerId}/assessment", [
                'loss_amount' => '20000.00',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.loss_amount', '20000.00');
    }

    public function test_worker_assessment_delete(): void
    {
        $workerId = $this->createWorker();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/worker/{$workerId}/assessment", [
                'loss_amount' => '15000.00',
            ])
            ->assertCreated();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->deleteJson("/api/v1/ep/{$this->epId}/worker/{$workerId}/assessment");

        $response->assertOk();
    }

    // ═══════════════════════════════════════════════════════════════
    // AUTH
    // ═══════════════════════════════════════════════════════════════

    public function test_unauthenticated_gets_401(): void
    {
        $landId = $this->createLand();

        $response = $this->getJson("/api/v1/ep/{$this->epId}/land/{$landId}/assessment");

        $response->assertStatus(401);
    }

    // ── Parent Entity Helpers ────────────────────────────────────

    private function createLand(): int
    {
        return DB::table('land')->insertGetId([
            'affected_person_id' => $this->epId,
            'land_type_id' => DB::table('land_type')->value('land_type_id'),
            'land_status_id' => 1,
            'ownership_type_id' => DB::table('ownership_type')->value('ownership_type_id'),
            'district_id' => $this->districtId,
            'upazila_id' => $this->upazilaId,
            'land_area' => '10.0000',
            'land_area_unit_id' => DB::table('unit')->value('unit_id'),
            'land_use_type_id' => DB::table('land_use_type')->value('land_use_type_id'),
        ]);
    }

    private function createCrop(int $landId): int
    {
        return DB::table('crop')->insertGetId([
            'land_id' => $landId,
            'crop_type_id' => DB::table('crop_type')->value('crop_type_id'),
            'crop_season_id' => DB::table('crop_season')->value('crop_season_id'),
            'cultivated_area' => '5.0000',
            'area_unit_id' => DB::table('unit')->value('unit_id'),
            'damage_percentage' => '50.00',
        ]);
    }

    private function createInfrastructure(): int
    {
        return DB::table('infrastructure')->insertGetId([
            'affected_person_id' => $this->epId,
            'infrastructure_type_id' => DB::table('infrastructure_type')->value('infrastructure_type_id'),
            'usage_type_id' => DB::table('usage_type')->value('usage_type_id'),
            'area_sqft' => '200.00',
            'damage_percentage' => '75.00',
        ]);
    }

    private function createTree(): int
    {
        return DB::table('tree')->insertGetId([
            'affected_person_id' => $this->epId,
            'tree_type_id' => DB::table('tree_type')->value('tree_type_id'),
            'tree_age_category_id' => DB::table('tree_age_category')->value('tree_age_category_id'),
            'quantity' => 10,
        ]);
    }

    private function createBusiness(): int
    {
        return DB::table('business')->insertGetId([
            'affected_person_id' => $this->epId,
            'business_ownership_type_id' => DB::table('business_ownership_type')->value('business_ownership_type_id'),
            'ownership_code' => 'OWNER',
            'business_type_id' => DB::table('business_type')->value('business_type_id'),
            'business_category_id' => DB::table('business_category')->value('business_category_id'),
            'business_name' => 'Test Business '.Str::random(5),
            'capital_amount' => '50000.00',
            'has_income_tax' => 0,
        ]);
    }

    private function createWorker(): int
    {
        return DB::table('worker')->insertGetId([
            'affected_person_id' => $this->epId,
            'name' => 'Test Worker '.Str::random(5),
            'gender_id' => DB::table('gender')->value('gender_id'),
            'employment_type_id' => DB::table('employment_type')->value('employment_type_id'),
            'monthly_income' => '12000.00',
        ]);
    }

    // ── Rate Helpers ─────────────────────────────────────────────

    private function createLandRate(): int
    {
        $landTypeId = DB::table('land_type')->value('land_type_id');
        $landUseTypeId = DB::table('land_use_type')->value('land_use_type_id');
        $unitId = DB::table('unit')->value('unit_id');

        return DB::table('land_rate')->insertGetId([
            'land_type_id' => $landTypeId,
            'land_use_type_id' => $landUseTypeId,
            'unit_id' => $unitId,
            'rate_per_unit' => '500.00',
            'is_active' => 1,
        ]);
    }

    private function createCropRate(): int
    {
        $cropTypeId = DB::table('crop_type')->value('crop_type_id');
        $cropSeasonId = DB::table('crop_season')->value('crop_season_id');
        $unitId = DB::table('unit')->value('unit_id');

        return DB::table('crop_rate')->insertGetId([
            'crop_type_id' => $cropTypeId,
            'crop_season_id' => $cropSeasonId,
            'unit_id' => $unitId,
            'rate_per_unit' => '300.00',
            'is_active' => 1,
        ]);
    }

    private function createInfrastructureRate(): int
    {
        $infraTypeId = DB::table('infrastructure_type')->value('infrastructure_type_id');
        $usageTypeId = DB::table('usage_type')->value('usage_type_id');

        return DB::table('infrastructure_rate')->insertGetId([
            'infrastructure_type_id' => $infraTypeId,
            'usage_type_id' => $usageTypeId,
            'rate_per_sqft' => '45.00',
            'is_active' => 1,
        ]);
    }

    private function createTreeRate(): int
    {
        $treeTypeId = DB::table('tree_type')->value('tree_type_id');
        $treeAgeCategoryId = DB::table('tree_age_category')->value('tree_age_category_id');

        return DB::table('tree_rate')->insertGetId([
            'tree_type_id' => $treeTypeId,
            'tree_age_category_id' => $treeAgeCategoryId,
            'rate_per_tree' => '200.00',
            'is_active' => 1,
        ]);
    }
}
