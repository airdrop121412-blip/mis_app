<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Additional (second-payment) voucher tests.
 *
 * Scenario: EP is PAID with an original voucher. New loss records (worker,
 * structure, ...) entered afterwards must generate payment_cycle-2
 * compensations and a new additional voucher, while the original payment
 * records remain completely unchanged.
 */
class AdditionalVoucherTest extends TestCase
{
    use DatabaseTransactions;

    private string $token;

    private int $userId;

    private int $epId;

    private int $ecId;

    private int $originalCompensationId;

    private int $originalVoucherId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userId = DB::table('users')->insertGetId([
            'username' => 'addv_admin_'.Str::random(8),
            'full_name' => 'Additional Voucher Admin',
            'password_hash' => bcrypt('AdminPass@123'),
            'is_active' => 1,
        ]);
        DB::table('user_role')->insert(['user_id' => $this->userId, 'role_id' => 1]);

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

        $projectId = DB::table('project')->insertGetId([
            'name' => 'Additional Voucher Project',
            'project_code' => 'AVP-'.Str::random(5),
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'is_active' => 1,
        ]);

        $districtId = DB::table('district')->insertGetId([
            'name' => 'AddV District '.Str::random(5),
            'code' => 'A'.rand(10, 99).Str::random(3),
            'is_active' => 1,
        ]);
        $upazilaId = DB::table('upazila')->insertGetId([
            'name' => 'AddV Upazila '.Str::random(5),
            'code' => 'A'.rand(10, 99).Str::random(3),
            'district_id' => $districtId,
            'is_active' => 1,
        ]);

        // PAID EP (original payment completed)
        $this->epId = DB::table('affected_person')->insertGetId([
            'project_id' => $projectId,
            'ep_id' => 'ADV'.rand(1000, 9999),
            'name' => 'Paid Person',
            'nid_number' => (string) rand(1000000000, 9999999999),
            'mobile' => '017'.rand(10000000, 99999999),
            'gender_id' => 1,
            'district_id' => $districtId,
            'upazila_id' => $upazilaId,
            'land_status_id' => 1,
            'rc_type_id' => 1,
            'monthly_income' => '5000.00',
            'monthly_expense' => '3000.00',
            'yearly_income' => '200000.00',
            'status' => 'PAID',
            'is_household_head' => 1,
        ]);

        $epicadId = DB::table('epicad')->insertGetId([
            'affected_person_id' => $this->epId,
            'issue_date' => now()->toDateString(),
            'print_status' => 'NOT_PRINTED',
        ]);

        $this->ecId = DB::table('ec')->insertGetId([
            'affected_person_id' => $this->epId,
            'epicad_id' => $epicadId,
            'ec_number' => 'EC-'.now()->format('Ymd').'-'.rand(1000, 9999),
            'assessment_date' => now()->toDateString(),
            'total_assessed_loss' => '8000.00',
            'total_compensation' => '8000.00',
            'status' => 'APPROVED',
        ]);

        DB::table('ec_line_item')->insert([
            'ec_id' => $this->ecId,
            'category' => 'CfL',
            'description' => 'Land',
            'assessed_amount' => '8000.00',
        ]);

        // Original (cycle-1) compensation, attached to the original PAID voucher
        $this->originalCompensationId = DB::table('compensation')->insertGetId([
            'ec_id' => $this->ecId,
            'category' => 'CfL',
            'assessed_amount' => '8000.00',
            'approved_amount' => '8000.00',
            'status' => 'APPROVED',
            'payment_cycle' => 1,
        ]);

        $this->originalVoucherId = DB::table('voucher')->insertGetId([
            'ec_id' => $this->ecId,
            'voucher_number' => 'VOU-ORIG-'.Str::random(6),
            'voucher_date' => now()->toDateString(),
            'approved_amount' => '8000.00',
            'payment_status' => 'PAID',
            'prepared_by' => $this->userId,
            'approved_by' => $this->userId,
        ]);

        DB::table('voucher_compensation')->insert([
            'voucher_id' => $this->originalVoucherId,
            'compensation_id' => $this->originalCompensationId,
            'amount' => '8000.00',
        ]);
    }

    /**
     * Register a new structure (with assessment) as a second-payment record.
     * CfS for the additional voucher must equal the structure's assessed loss.
     */
    private function addStructureRecord(float $assessedLoss = 25000.00): int
    {
        $typeId = DB::table('infrastructure_type')->insertGetId([
            'name' => 'AddV Type '.Str::random(6),
            'code' => 'AVT-'.Str::random(8),
            'is_active' => 1,
        ]);
        $usageId = DB::table('usage_type')->insertGetId([
            'name' => 'AddV Usage '.Str::random(6),
            'code' => 'AVU-'.Str::random(8),
            'is_active' => 1,
        ]);
        $rateId = DB::table('infrastructure_rate')->insertGetId([
            'infrastructure_type_id' => $typeId,
            'usage_type_id' => $usageId,
            'rate_per_sqft' => '100.00',
            'is_active' => 1,
        ]);

        $structureId = DB::table('infrastructure')->insertGetId([
            'affected_person_id' => $this->epId,
            'infrastructure_type_id' => $typeId,
            'usage_type_id' => $usageId,
            'area_sqft' => '250.00',
            'damage_percentage' => '100.00',
        ]);

        DB::table('infrastructure_assessment')->insert([
            'infrastructure_id' => $structureId,
            'rate_id' => $rateId,
            'area_sqft' => '250.00',
            'rate_per_sqft' => '100.00',
            'damage_percentage' => '100.00',
            'assessed_loss' => number_format($assessedLoss, 2, '.', ''),
        ]);

        DB::table('additional_payment_record')->insert([
            'affected_person_id' => $this->epId,
            'record_type' => 'infrastructure',
            'record_id' => $structureId,
            'voucher_id' => null,
            'payment_cycle' => 2,
            'locked_at' => null,
        ]);

        return $structureId;
    }

    private const NEW_STRUCTURE_LOSS = '25000.00';

    public function test_additional_voucher_contains_only_new_data(): void
    {
        $this->addStructureRecord();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/voucher");

        $response->assertCreated();
        $response->assertJsonPath('data.payment_status', 'PENDING');
        // Additional voucher total = ONLY the new structure's compensation
        $response->assertJsonPath('data.approved_amount', self::NEW_STRUCTURE_LOSS);

        $voucherId = $response->json('data.voucher_id');

        // New CfS compensation exists as payment_cycle 2 and is attached
        $cfs = DB::table('compensation')
            ->where('ec_id', $this->ecId)
            ->where('category', 'CfS')
            ->where('payment_cycle', 2)
            ->first();
        $this->assertNotNull($cfs, 'A payment_cycle-2 CfS compensation must be created');
        $this->assertDatabaseHas('voucher_compensation', [
            'voucher_id' => $voucherId,
            'compensation_id' => $cfs->compensation_id,
        ]);

        // The show endpoint exposes the cycle metadata used by the voucher document
        $show = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson("/api/v1/ep/{$this->epId}/voucher/{$voucherId}");
        $show->assertOk();
        $show->assertJsonPath('data.voucher_compensations.0.compensation.payment_cycle', 2);
    }

    public function test_original_payment_records_remain_unchanged(): void
    {
        $this->addStructureRecord();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/voucher")
            ->assertCreated();

        // Original compensation untouched
        $original = DB::table('compensation')->where('compensation_id', $this->originalCompensationId)->first();
        $this->assertSame('8000.00', $original->approved_amount);
        $this->assertSame(1, (int) $original->payment_cycle);

        // Original voucher untouched
        $originalVoucher = DB::table('voucher')->where('voucher_id', $this->originalVoucherId)->first();
        $this->assertSame('8000.00', $originalVoucher->approved_amount);
        $this->assertSame('PAID', $originalVoucher->payment_status);
    }

    public function test_paid_ep_without_new_records_cannot_create_voucher(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/voucher");

        // No unattached approved compensations and no new loss records → rejected
        $response->assertStatus(422);
    }

    public function test_worklist_lists_paid_ep_with_new_data_and_clears_after_voucher(): void
    {
        $worklist = '/api/v1/ep/additional-payment-pending';

        // Before adding new data: EP not in the worklist
        $before = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson($worklist)
            ->assertOk();
        $this->assertFalse(collect($before->json('data'))
            ->contains(fn ($row) => $row['affected_person_id'] === $this->epId));

        // New structure entered on the PAID EP → appears immediately with remark
        $this->addStructureRecord();
        $after = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson($worklist)
            ->assertOk();
        $row = collect($after->json('data'))
            ->first(fn ($row) => $row['affected_person_id'] === $this->epId);
        $this->assertNotNull($row, 'PAID EP with new loss data must appear in the worklist');
        $this->assertStringContainsString('Additional Payment', $row['remark']);
        $this->assertEquals('25000.00', $row['estimated_additional_amount']);

        // After the additional voucher is created: cleared from the worklist
        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/voucher")
            ->assertCreated();
        $final = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson($worklist)
            ->assertOk();
        $this->assertFalse(collect($final->json('data'))
            ->contains(fn ($row) => $row['affected_person_id'] === $this->epId));
    }

    public function test_voucher_list_marks_additional_vouchers(): void
    {
        $this->addStructureRecord();
        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/voucher")
            ->assertCreated();

        $list = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson("/api/v1/ep/{$this->epId}/voucher")
            ->assertOk();

        $rows = collect($list->json('data'))->keyBy('voucher_id');
        // Original voucher: not additional
        $this->assertFalse((bool) $rows[$this->originalVoucherId]['is_additional']);
        // The second voucher: additional (persistent cycle-2 data, not PAID status)
        $additional = $rows->first(fn ($v) => $v['voucher_id'] !== $this->originalVoucherId);
        $this->assertTrue((bool) $additional['is_additional']);
    }
}
