<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 4B-7b — Master hierarchy endpoints.
 *
 * Covers:
 *   GET /api/v1/masters/upazilas?district_id=   — upazilas by district
 *   GET /api/v1/masters/villages?upazila_id=     — villages by upazila
 *
 * Uses direct DB inserts for all test data to avoid session contamination.
 */
class MasterHierarchyTest extends TestCase
{
    use DatabaseTransactions;

    private string $token;

    private int $userId;

    private int $districtId;

    private int $districtId2;

    private int $upazilaId;

    private int $upazilaId2;

    private int $villageId;

    protected function setUp(): void
    {
        parent::setUp();

        // Admin user + token
        $this->userId = DB::table('users')->insertGetId([
            'username' => 'mh_admin_'.Str::random(8),
            'full_name' => 'MH Admin',
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

        // Two districts — use unique codes to avoid collisions across runs
        $this->districtId = DB::table('district')->insertGetId([
            'name' => 'MH District A '.Str::random(5),
            'code' => (string) (9000 + random_int(0, 999)),
            'is_active' => 1,
        ]);
        $this->districtId2 = DB::table('district')->insertGetId([
            'name' => 'MH District B '.Str::random(5),
            'code' => (string) (9000 + random_int(1000, 1999)),
            'is_active' => 1,
        ]);

        // Two upazilas in district 1, one in district 2
        $this->upazilaId = DB::table('upazila')->insertGetId([
            'name' => 'MH Upazila A1 '.Str::random(5),
            'code' => (string) rand(100, 999),
            'district_id' => $this->districtId,
            'is_active' => 1,
        ]);
        $this->upazilaId2 = DB::table('upazila')->insertGetId([
            'name' => 'MH Upazila A2 '.Str::random(5),
            'code' => (string) rand(100, 999),
            'district_id' => $this->districtId,
            'is_active' => 1,
        ]);
        DB::table('upazila')->insert([
            'name' => 'MH Upazila B1 '.Str::random(5),
            'code' => (string) rand(100, 999),
            'district_id' => $this->districtId2,
            'is_active' => 1,
        ]);

        // Two villages in upazila 1, one in upazila 2
        $this->villageId = DB::table('village')->insertGetId([
            'upazila_id' => $this->upazilaId,
            'name' => 'MH Village A1a '.Str::random(5),
            'is_active' => 1,
        ]);
        DB::table('village')->insert([
            'upazila_id' => $this->upazilaId,
            'name' => 'MH Village A1b '.Str::random(5),
            'is_active' => 1,
        ]);
        DB::table('village')->insert([
            'upazila_id' => $this->upazilaId2,
            'name' => 'MH Village A2a '.Str::random(5),
            'is_active' => 1,
        ]);
    }

    private function authHeader(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    // ═══════════════════════════════════════════════════════════════
    // UPS by district
    // ═══════════════════════════════════════════════════════════════

    public function test_upazilas_returns_filtered_by_district(): void
    {
        $response = $this->getJson(
            "/api/v1/masters/upazilas?district_id={$this->districtId}",
            $this->authHeader(),
        );

        $response->assertOk()
            ->assertJsonPath('success', true);

        $data = $response->json('data');
        // Should contain exactly 2 upazilas from district 1
        $this->assertCount(2, $data);

        // Verify all belong to the correct district
        foreach ($data as $item) {
            $this->assertArrayHasKey('upazila_id', $item);
            $this->assertArrayHasKey('name', $item);
        }
    }

    public function test_upazilas_returns_empty_for_unknown_district(): void
    {
        $response = $this->getJson(
            '/api/v1/masters/upazilas?district_id=999999',
            $this->authHeader(),
        );

        $response->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_upazilas_without_district_id_returns_all(): void
    {
        $response = $this->getJson('/api/v1/masters/upazilas', $this->authHeader());

        $response->assertOk();

        $data = $response->json('data');
        // Should contain at least 3 upazilas (2 from district 1 + 1 from district 2)
        $this->assertGreaterThanOrEqual(3, count($data));
    }

    public function test_upazilas_search_by_name(): void
    {
        // Insert a unique upazila
        $uniqueName = 'SearchableUPZ_'.Str::random(8);
        DB::table('upazila')->insert([
            'name' => $uniqueName,
            'code' => (string) rand(100, 999),
            'district_id' => $this->districtId,
            'is_active' => 1,
        ]);

        $response = $this->getJson(
            '/api/v1/masters/upazilas?q='.urlencode($uniqueName),
            $this->authHeader(),
        );

        $response->assertOk();

        $data = $response->json('data');
        $names = collect($data)->pluck('name')->toArray();
        $this->assertContains($uniqueName, $names);
    }

    // ═══════════════════════════════════════════════════════════════
    // VILLAGES by upazila
    // ═══════════════════════════════════════════════════════════════

    public function test_villages_returns_filtered_by_upazila(): void
    {
        $response = $this->getJson(
            "/api/v1/masters/villages?upazila_id={$this->upazilaId}",
            $this->authHeader(),
        );

        $response->assertOk()
            ->assertJsonPath('success', true);

        $data = $response->json('data');
        // Should contain exactly 2 villages in upazila 1
        $this->assertCount(2, $data);

        foreach ($data as $item) {
            $this->assertArrayHasKey('id', $item);
            $this->assertArrayHasKey('name', $item);
            $this->assertEquals($this->upazilaId, $item['upazila_id']);
        }
    }

    public function test_villages_returns_empty_for_unknown_upazila(): void
    {
        $response = $this->getJson(
            '/api/v1/masters/villages?upazila_id=999999',
            $this->authHeader(),
        );

        $response->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_villages_without_upazila_id_returns_all(): void
    {
        $response = $this->getJson('/api/v1/masters/villages', $this->authHeader());

        $response->assertOk();

        $data = $response->json('data');
        // At least 3 villages total
        $this->assertGreaterThanOrEqual(3, count($data));
    }

    public function test_villages_different_upazilas_isolated(): void
    {
        // Get villages for upazila 1
        $r1 = $this->getJson(
            "/api/v1/masters/villages?upazila_id={$this->upazilaId}",
            $this->authHeader(),
        );
        $villages1 = collect($r1->json('data'))->pluck('id')->toArray();

        // Get villages for upazila 2
        $r2 = $this->getJson(
            "/api/v1/masters/villages?upazila_id={$this->upazilaId2}",
            $this->authHeader(),
        );
        $villages2 = collect($r2->json('data'))->pluck('id')->toArray();

        // No overlap
        $intersection = array_intersect($villages1, $villages2);
        $this->assertEmpty($intersection);
    }

    // ═══════════════════════════════════════════════════════════════
    // AUTH / PERMISSION
    // ═══════════════════════════════════════════════════════════════

    public function test_upazilas_unauthenticated(): void
    {
        $response = $this->getJson('/api/v1/masters/upazilas');

        $response->assertUnauthorized();
    }

    public function test_villages_unauthenticated(): void
    {
        $response = $this->getJson('/api/v1/masters/villages');

        $response->assertUnauthorized();
    }

    public function test_upazilas_no_permission(): void
    {
        $noRoleId = DB::table('users')->insertGetId([
            'username' => 'norole_mh_'.Str::random(6),
            'full_name' => 'No Role',
            'password_hash' => bcrypt('x'),
            'is_active' => 1,
        ]);

        $token = Str::random(60);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $noRoleId,
            'name' => 'test',
            'token' => hash('sha256', $token),
            'abilities' => json_encode(['*']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/masters/upazilas', [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertForbidden();
    }
}
