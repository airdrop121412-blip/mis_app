<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Grant & Assistance tests — /api/v1/grant-assistance.
 *
 * The Grant and Assistance config feeds the EC File compensation for the
 * LDC / DA / SRC / HTG / HCG / CAVHH / AVFHH / MTG / SGfT / GfWL heads.
 * Read requires master.view; write requires master.manage (admin only).
 */
class GrantAssistanceTest extends TestCase
{
    use DatabaseTransactions;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $userId = DB::table('users')->insertGetId([
            'username' => 'grant_admin_'.Str::random(8),
            'full_name' => 'Grant Admin',
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

    public function test_admin_can_list_all_grant_items(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/grant-assistance');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(10, 'data');
        $response->assertJsonStructure([
            'data' => ['0' => [
                'id',
                'grant_code',
                'grant_name',
                'calculation_type',
                'amount',
                'percentage',
                'percentage_base',
                'category',
                'multiplier',
                'is_active',
            ]],
        ]);
        $response->assertJsonPath('data.0.grant_code', 'LDC');
        $response->assertJsonPath('data.0.calculation_type', 'PERCENTAGE');
    }

    public function test_admin_can_update_a_grant_item(): void
    {
        $update = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson('/api/v1/grant-assistance/LDC', [
                'grant_name' => 'Land Development Cost',
                'calculation_type' => 'FIXED',
                'amount' => '15000.00',
                'percentage' => '0.00',
                'percentage_base' => 'PAVC',
                'category' => null,
                'multiplier' => '0.00',
                'is_active' => true,
            ]);

        $update->assertOk();
        $update->assertJsonPath('data.amount', '15000.00');
        $update->assertJsonPath('data.calculation_type', 'FIXED');

        $list = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson('/api/v1/grant-assistance');
        $list->assertOk();
        $row = collect($list->json('data'))->firstWhere('grant_code', 'LDC');
        $this->assertSame('15000.00', $row['amount']);
    }

    public function test_percentage_calculation_type_needs_base(): void
    {
        $update = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson('/api/v1/grant-assistance/HTG', [
                'grant_name' => 'House Transfer Grant',
                'calculation_type' => 'PERCENTAGE',
                'amount' => '0.00',
                'percentage' => '10.00',
                'percentage_base' => 'NOT_A_BASE',
                'category' => null,
                'multiplier' => '0.00',
            ]);

        $update->assertStatus(422);
        $update->assertJsonPath('success', false);
    }

    public function test_validation_rejects_bad_payload(): void
    {
        $update = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson('/api/v1/grant-assistance/SRC', [
                'grant_name' => '',
                'calculation_type' => 'WEIRD',
                'amount' => '-5',
                'percentage' => '200',
                'percentage_base' => null,
                'category' => null,
                'multiplier' => '-1',
            ]);

        $update->assertStatus(422);
        $update->assertJsonPath('success', false);
    }

    public function test_admin_can_update_da_rate_records(): void
    {
        $update = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson('/api/v1/grant-assistance/DA', [
                'grant_name' => 'Dislocation Allowance',
                'calculation_type' => 'MULTIPLIER',
                'amount' => '0.00',
                'percentage' => '0.00',
                'percentage_base' => null,
                'category' => null,
                'multiplier' => '0.00',
                'is_active' => true,
                'rates' => [
                    [
                        'district_id' => 3,
                        'upazila_id' => 4,
                        'land_category' => 'Homestead',
                        'multiplier' => '150.00',
                        'is_active' => true,
                    ],
                    [
                        'district_id' => null,
                        'upazila_id' => null,
                        'land_category' => null,
                        'multiplier' => '100.00',
                        'is_active' => true,
                    ],
                ],
            ]);

        $update->assertOk();
        $update->assertJsonCount(2, 'data.rates');
        $this->assertSame(3, $update->json('data.rates.0.district_id'));
        $this->assertSame('150.00', $update->json('data.rates.0.multiplier'));
        $this->assertSame('Homestead', $update->json('data.rates.0.land_category'));
        $this->assertSame('100.00', $update->json('data.rates.1.multiplier'));

        // Records are replaced, not appended, on the next update.
        $second = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson('/api/v1/grant-assistance/DA', [
                'grant_name' => 'Dislocation Allowance',
                'calculation_type' => 'MULTIPLIER',
                'amount' => '0.00',
                'percentage' => '0.00',
                'percentage_base' => null,
                'category' => null,
                'multiplier' => '0.00',
                'is_active' => true,
                'rates' => [
                    [
                        'district_id' => null,
                        'upazila_id' => null,
                        'land_category' => null,
                        'multiplier' => '200.00',
                        'is_active' => true,
                    ],
                ],
            ]);

        $second->assertOk();
        $second->assertJsonCount(1, 'data.rates');
        $this->assertSame('200.00', $second->json('data.rates.0.multiplier'));
    }

    public function test_da_rate_records_validate_bad_multiplier(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson('/api/v1/grant-assistance/DA', [
                'grant_name' => 'Dislocation Allowance',
                'calculation_type' => 'MULTIPLIER',
                'amount' => '0.00',
                'percentage' => '0.00',
                'percentage_base' => null,
                'category' => null,
                'multiplier' => '0.00',
                'is_active' => true,
                'rates' => [
                    [
                        'district_id' => 3,
                        'upazila_id' => 4,
                        'land_category' => 'Homestead',
                        'multiplier' => '-5',
                        'is_active' => true,
                    ],
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    public function test_unknown_grant_code_returns_404(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson('/api/v1/grant-assistance/NOPE', [
                'grant_name' => 'x',
                'calculation_type' => 'FIXED',
                'amount' => '0.00',
                'percentage' => '0.00',
                'percentage_base' => null,
                'category' => null,
                'multiplier' => '0.00',
            ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    public function test_write_is_forbidden_for_non_admin(): void
    {
        $user = User::factory()->create([
            'username' => 'grant_op_'.fake()->unique()->numerify('#####'),
            'password_hash' => bcrypt('OpPass@123'),
        ]);
        DB::table('user_role')->insert([
            'user_id' => $user->user_id,
            'role_id' => 2, // OPERATOR — master.view but not master.manage
        ]);

        $opToken = Str::random(60);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->user_id,
            'name' => 'test',
            'token' => hash('sha256', $opToken),
            'abilities' => json_encode(['*']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $read = $this->withHeader('Authorization', 'Bearer '.$opToken)
            ->getJson('/api/v1/grant-assistance');
        $read->assertOk();

        $write = $this->withHeader('Authorization', 'Bearer '.$opToken)
            ->putJson('/api/v1/grant-assistance/LDC', [
                'grant_name' => 'Land Development Cost',
                'calculation_type' => 'FIXED',
                'amount' => '100.00',
                'percentage' => '0.00',
                'percentage_base' => 'PAVC',
                'category' => null,
                'multiplier' => '0.00',
            ]);
        $write->assertStatus(403);
    }
}
