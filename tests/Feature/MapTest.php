<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Map query tests (architecture §17, §5.11 #74–75).
 *
 * Covers: MapController (surveys, clusters)
 *
 * Uses direct DB inserts for all test data to avoid session contamination.
 */
class MapTest extends TestCase
{
    use DatabaseTransactions;

    private string $token;

    private int $userId;

    private int $projectId;

    private int $districtId;

    private int $upazilaId;

    protected function setUp(): void
    {
        parent::setUp();

        // Admin user with map.view permission
        $this->userId = DB::table('users')->insertGetId([
            'username' => 'map_admin_'.Str::random(8),
            'full_name' => 'Map Admin',
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
            'name' => 'Map Test Project',
            'project_code' => 'MTP-'.Str::random(5),
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'is_active' => 1,
        ]);

        // District + Upazila
        $this->districtId = DB::table('district')->insertGetId([
            'name' => 'Map District '.Str::random(5),
            'code' => (string) rand(10, 99),
            'is_active' => 1,
        ]);
        $this->upazilaId = DB::table('upazila')->insertGetId([
            'name' => 'Map Upazila '.Str::random(5),
            'code' => (string) rand(10, 99),
            'district_id' => $this->districtId,
            'is_active' => 1,
        ]);

        // Seed several EPs with GPS coordinates in different locations
        $locations = [
            // Inside the bbox we'll query (Dhaka-ish area)
            ['lat' => 23.8103, 'lng' => 90.4125, 'status' => 'APPROVED'],
            ['lat' => 23.8200, 'lng' => 90.4200, 'status' => 'SUBMITTED'],
            ['lat' => 23.7900, 'lng' => 90.4000, 'status' => 'APPROVED'],
            // Outside the bbox
            ['lat' => 22.3500, 'lng' => 89.1800, 'status' => 'DRAFT'],
        ];

        foreach ($locations as $i => $loc) {
            DB::table('affected_person')->insert([
                'project_id' => $this->projectId,
                'ep_id' => 'MAP'.(1000 + $i),
                'name' => "Map Person {$i}",
                'nid_number' => (string) (1000000000 + $i),
                'mobile' => '017'.str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                'gender_id' => 1,
                'district_id' => $this->districtId,
                'upazila_id' => $this->upazilaId,
                'land_status_id' => 1,
                'rc_type_id' => 1,
                'monthly_income' => '5000.00',
                'monthly_expense' => '3000.00',
                'gps_latitude' => $loc['lat'],
                'gps_longitude' => $loc['lng'],
                'status' => $loc['status'],
                'is_household_head' => 1,
            ]);
        }
    }

    private function authHeader(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    // ═══════════════════════════════════════════════════════════════
    // SURVEYS
    // ═══════════════════════════════════════════════════════════════

    public function test_surveys_returns_points_in_bbox(): void
    {
        // Bbox that covers Dhaka-area points (23.78–23.83 lat, 90.39–90.43 lng)
        $bbox = '90.39,23.78,90.43,23.83';

        $response = $this->getJson("/api/v1/map/surveys?bbox={$bbox}", $this->authHeader());

        $response->assertOk()
            ->assertJsonPath('success', true);

        $points = $response->json('data');
        $this->assertGreaterThanOrEqual(3, count($points));

        // Verify structure
        foreach ($points as $point) {
            $this->assertArrayHasKey('affected_person_id', $point);
            $this->assertArrayHasKey('gps_latitude', $point);
            $this->assertArrayHasKey('gps_longitude', $point);
        }
    }

    public function test_surveys_excludes_points_outside_bbox(): void
    {
        // Small bbox that only contains one point
        $bbox = '90.41,23.80,90.42,23.82';

        $response = $this->getJson("/api/v1/map/surveys?bbox={$bbox}", $this->authHeader());

        $response->assertOk();

        $points = $response->json('data');
        $this->assertGreaterThanOrEqual(1, count($points));

        // All points should be within the bbox
        foreach ($points as $point) {
            $this->assertGreaterThanOrEqual(23.80, (float) $point['gps_latitude']);
            $this->assertLessThanOrEqual(23.82, (float) $point['gps_latitude']);
            $this->assertGreaterThanOrEqual(90.41, (float) $point['gps_longitude']);
            $this->assertLessThanOrEqual(90.42, (float) $point['gps_longitude']);
        }
    }

    public function test_surveys_filter_by_project_id(): void
    {
        // Create another project with a point in the same bbox
        $otherProject = DB::table('project')->insertGetId([
            'name' => 'Other Project',
            'project_code' => 'OTH-'.Str::random(5),
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'is_active' => 1,
        ]);

        DB::table('affected_person')->insert([
            'project_id' => $otherProject,
            'ep_id' => 'OTH1000',
            'name' => 'Other Person',
            'nid_number' => '9999999999',
            'mobile' => '01812345678',
            'gender_id' => 1,
            'district_id' => $this->districtId,
            'upazila_id' => $this->upazilaId,
            'land_status_id' => 1,
            'rc_type_id' => 1,
            'monthly_income' => '5000.00',
            'monthly_expense' => '3000.00',
            'gps_latitude' => 23.8150,
            'gps_longitude' => 90.4150,
            'status' => 'DRAFT',
            'is_household_head' => 1,
        ]);

        $bbox = '90.39,23.78,90.43,23.83';

        // With project filter — should exclude the other project's point
        $response = $this->getJson(
            "/api/v1/map/surveys?bbox={$bbox}&project_id={$this->projectId}",
            $this->authHeader(),
        );

        $response->assertOk();

        $points = $response->json('data');
        foreach ($points as $point) {
            $this->assertEquals($this->projectId, $point['project_id']);
        }
    }

    public function test_surveys_filter_by_status(): void
    {
        $bbox = '90.39,23.78,90.43,23.83';

        $response = $this->getJson(
            "/api/v1/map/surveys?bbox={$bbox}&status=APPROVED",
            $this->authHeader(),
        );

        $response->assertOk();

        $points = $response->json('data');
        foreach ($points as $point) {
            $this->assertEquals('APPROVED', $point['status']);
        }
    }

    public function test_surveys_invalid_bbox_rejected(): void
    {
        $response = $this->getJson('/api/v1/map/surveys?bbox=1,2', $this->authHeader());

        $response->assertUnprocessable();
    }

    public function test_surveys_missing_bbox_rejected(): void
    {
        $response = $this->getJson('/api/v1/map/surveys', $this->authHeader());

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['bbox']);
    }

    // ═══════════════════════════════════════════════════════════════
    // CLUSTERS
    // ═══════════════════════════════════════════════════════════════

    public function test_clusters_returns_grid_counts(): void
    {
        $bbox = '90.39,23.78,90.43,23.83';

        $response = $this->getJson("/api/v1/map/clusters?bbox={$bbox}", $this->authHeader());

        $response->assertOk()
            ->assertJsonPath('success', true);

        $clusters = $response->json('data');
        $this->assertNotEmpty($clusters);

        // Verify structure
        foreach ($clusters as $cluster) {
            $this->assertArrayHasKey('cluster_lat', $cluster);
            $this->assertArrayHasKey('cluster_lng', $cluster);
            $this->assertArrayHasKey('count', $cluster);
            $this->assertGreaterThan(0, $cluster['count']);
        }
    }

    public function test_clusters_total_count_matches(): void
    {
        $bbox = '90.39,23.78,90.43,23.83';

        $response = $this->getJson("/api/v1/map/clusters?bbox={$bbox}", $this->authHeader());

        $response->assertOk();

        $clusters = $response->json('data');
        $totalCount = array_sum(array_column($clusters, 'count'));

        // Should match the 3 points inside the bbox
        $this->assertEquals(3, $totalCount);
    }

    public function test_clusters_filter_by_project(): void
    {
        $bbox = '90.39,23.78,90.43,23.83';

        $response = $this->getJson(
            "/api/v1/map/clusters?bbox={$bbox}&project_id={$this->projectId}",
            $this->authHeader(),
        );

        $response->assertOk();

        $clusters = $response->json('data');
        $totalCount = array_sum(array_column($clusters, 'count'));

        // Only the 3 points from our project (no other project points in bbox)
        $this->assertEquals(3, $totalCount);
    }

    public function test_clusters_invalid_bbox_rejected(): void
    {
        $response = $this->getJson('/api/v1/map/clusters?bbox=bad', $this->authHeader());

        $response->assertUnprocessable();
    }

    // ═══════════════════════════════════════════════════════════════
    // AUTH / PERMISSION
    // ═══════════════════════════════════════════════════════════════

    public function test_unauthenticated_returns_401(): void
    {
        $bbox = '90.39,23.78,90.43,23.83';

        $response = $this->getJson("/api/v1/map/surveys?bbox={$bbox}");

        $response->assertUnauthorized();
    }

    public function test_no_permission_returns_403(): void
    {
        // Create user with no role at all (empty user_role)
        $noRoleId = DB::table('users')->insertGetId([
            'username' => 'norole_map_'.Str::random(6),
            'full_name' => 'No Role User',
            'password_hash' => bcrypt('x'),
            'is_active' => 1,
        ]);
        // No user_role insert — this user has zero permissions

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

        $bbox = '90.39,23.78,90.43,23.83';
        $response = $this->getJson("/api/v1/map/surveys?bbox={$bbox}", [
            'Authorization' => "Bearer {$token}",
        ]);

        $response->assertForbidden();
    }
}
