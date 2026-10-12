<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Voucher + Payment tests.
 *
 * Full chain: EP → EPICAD → EC (APPROVED) → EC Line Items →
 * Compensation (APPROVED) → Voucher → Payment.
 *
 * Key business rules tested:
 *  - Payment status auto-calculated: PENDING → PARTIAL → PAID
 *  - Duplicate-payment prevention (UNIQUE compensation_id)
 *  - Payment cannot exceed voucher approved_amount
 *  - Voucher cancel requires no payments
 *  - Voiding payment recalculates status
 */
class VoucherPaymentTest extends TestCase
{
    use DatabaseTransactions;

    private string $token;

    private int $userId;

    private int $epId;

    private int $ecId;

    private int $compensationId;

    protected function setUp(): void
    {
        parent::setUp();

        // Admin user + token
        $this->userId = DB::table('users')->insertGetId([
            'username' => 'vouch_admin_'.Str::random(8),
            'full_name' => 'Voucher Admin',
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
        $projectId = DB::table('project')->insertGetId([
            'name' => 'Voucher Test Project',
            'project_code' => 'VTP-'.Str::random(5),
            'start_date' => '2024-01-01',
            'end_date' => '2024-12-31',
            'is_active' => 1,
        ]);

        // District + Upazila
        $districtId = DB::table('district')->insertGetId([
            'name' => 'Voucher District '.Str::random(5),
            'code' => 'VT'.rand(100, 999).Str::random(4),
            'is_active' => 1,
        ]);
        $upazilaId = DB::table('upazila')->insertGetId([
            'name' => 'Voucher Upazila '.Str::random(5),
            'code' => 'VT'.rand(100, 999).Str::random(4),
            'district_id' => $districtId,
            'is_active' => 1,
        ]);

        // EP (APPROVED)
        $this->epId = DB::table('affected_person')->insertGetId([
            'project_id' => $projectId,
            'ep_id' => 'VCH'.rand(1000, 9999),
            'name' => 'Voucher Person',
            'nid_number' => (string) rand(1000000000, 9999999999),
            'mobile' => '017'.rand(10000000, 99999999),
            'gender_id' => 1,
            'district_id' => $districtId,
            'upazila_id' => $upazilaId,
            'land_status_id' => 1,
            'rc_type_id' => 1,
            'monthly_income' => '5000.00',
            'monthly_expense' => '3000.00',
            'status' => 'APPROVED',
            'is_household_head' => 1,
        ]);

        // EPICAD
        $epicadId = DB::table('epicad')->insertGetId([
            'affected_person_id' => $this->epId,
            'issue_date' => now()->toDateString(),
            'print_status' => 'NOT_PRINTED',
        ]);

        // EC (APPROVED)
        $this->ecId = DB::table('ec')->insertGetId([
            'affected_person_id' => $this->epId,
            'epicad_id' => $epicadId,
            'ec_number' => 'EC-'.now()->format('Ymd').'-'.rand(1000, 9999),
            'assessment_date' => now()->toDateString(),
            'total_assessed_loss' => '10000.00',
            'total_compensation' => '0.00',
            'status' => 'APPROVED',
        ]);

        // EC Line Item
        DB::table('ec_line_item')->insert([
            'ec_id' => $this->ecId,
            'category' => 'LAND',
            'description' => 'Land damage',
            'assessed_amount' => '10000.00',
        ]);

        // Approved Compensation
        $this->compensationId = DB::table('compensation')->insertGetId([
            'ec_id' => $this->ecId,
            'category' => 'LAND',
            'assessed_amount' => '10000.00',
            'approved_amount' => '8000.00',
            'status' => 'APPROVED',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // VOUCHER — INDEX / SHOW
    // ═══════════════════════════════════════════════════════════════

    public function test_index_returns_empty_when_no_vouchers(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson("/api/v1/ep/{$this->epId}/voucher");

        $response->assertOk();
        $response->assertJsonPath('data', []);
    }

    public function test_show_returns_voucher_with_compensations(): void
    {
        $voucherId = $this->createVoucher();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->getJson("/api/v1/ep/{$this->epId}/voucher/{$voucherId}");

        $response->assertOk();
        $response->assertJsonPath('data.voucher_id', $voucherId);
    }

    // ═══════════════════════════════════════════════════════════════
    // VOUCHER — STORE
    // ═══════════════════════════════════════════════════════════════

    public function test_store_creates_voucher_with_compensations(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/voucher");

        $response->assertCreated();
        $response->assertJsonPath('data.payment_status', 'PENDING');

        // Voucher total = sum of its attached compensation lines (backend-authoritative).
        // The EP bootstrap may add grant-based compensations (e.g. CAVHH), so the
        // expected amount is derived from the attached lines, not hard-coded.
        $voucherId = $response->json('data.voucher_id');
        $expected = number_format(
            (float) DB::table('voucher_compensation')->where('voucher_id', $voucherId)->sum('amount'),
            2, '.', ''
        );
        $response->assertJsonPath('data.approved_amount', $expected);

        // Verify the original approved compensation is attached
        $this->assertDatabaseHas('voucher_compensation', [
            'voucher_id' => $voucherId,
            'compensation_id' => $this->compensationId,
        ]);
    }

    public function test_store_no_approved_compensations_rejected(): void
    {
        DB::table('compensation')->where('compensation_id', $this->compensationId)
            ->update(['status' => 'DRAFT']);

        // Make the EP ineligible for every bootstrapped grant amount
        // (CAVHH requires yearly_income < 90000) so there is nothing
        // left to create a voucher from.
        DB::table('affected_person')->where('affected_person_id', $this->epId)
            ->update(['yearly_income' => '100000.00']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/voucher");

        $response->assertStatus(422);
    }

    public function test_store_duplicate_voucher_rejected(): void
    {
        $this->createVoucher();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/voucher");

        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════
    // VOUCHER — UPDATE
    // ═══════════════════════════════════════════════════════════════

    public function test_update_pending_voucher(): void
    {
        $voucherId = $this->createVoucher();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson("/api/v1/ep/{$this->epId}/voucher/{$voucherId}", [
                'remarks' => 'Updated voucher',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.remarks', 'Updated voucher');
    }

    // ═══════════════════════════════════════════════════════════════
    // VOUCHER — DELETE
    // ═══════════════════════════════════════════════════════════════

    public function test_delete_pending_voucher_no_payments(): void
    {
        $voucherId = $this->createVoucher();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->deleteJson("/api/v1/ep/{$this->epId}/voucher/{$voucherId}");

        $response->assertOk();
        $this->assertDatabaseMissing('voucher', ['voucher_id' => $voucherId]);
        // Compensation should be detached
        $this->assertDatabaseMissing('voucher_compensation', ['voucher_id' => $voucherId]);
    }

    // ═══════════════════════════════════════════════════════════════
    // VOUCHER — VERIFY / APPROVE
    // ═══════════════════════════════════════════════════════════════

    public function test_verify_sets_verified_by(): void
    {
        $voucherId = $this->createVoucher();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/voucher/{$voucherId}/verify");

        $response->assertOk();
        $this->assertDatabaseHas('voucher', [
            'voucher_id' => $voucherId,
            'verified_by' => $this->userId,
        ]);
    }

    public function test_approve_sets_approved_by_and_marks_paid(): void
    {
        $voucherId = $this->createVoucher();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/voucher/{$voucherId}/approve");

        $response->assertOk();
        $this->assertDatabaseHas('voucher', [
            'voucher_id' => $voucherId,
            'approved_by' => $this->userId,
        ]);
        // Approval completes the disbursement → voucher shows Paid
        $this->assertDatabaseHas('voucher', [
            'voucher_id' => $voucherId,
            'payment_status' => 'PAID',
        ]);
        // Full payment auto-recorded in the ledger
        $paid = (float) DB::table('payment')->where('voucher_id', $voucherId)->sum('amount');
        $this->assertEquals(8000.00, $paid);
    }

    // ═══════════════════════════════════════════════════════════════
    // VOUCHER — CANCEL
    // ═══════════════════════════════════════════════════════════════

    public function test_cancel_pending_voucher(): void
    {
        $voucherId = $this->createVoucher();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/voucher/{$voucherId}/cancel");

        $response->assertOk();
        $response->assertJsonPath('data.payment_status', 'CANCELLED');
    }

    public function test_cancel_voucher_with_payments_rejected(): void
    {
        $voucherId = $this->createVoucher();
        $this->makePayment($voucherId, 2000.00);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/voucher/{$voucherId}/cancel");

        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════
    // VOUCHER — ATTACH COMPENSATION
    // ═══════════════════════════════════════════════════════════════

    public function test_attach_compensation_to_voucher(): void
    {
        // Create second compensation
        $comp2Id = DB::table('compensation')->insertGetId([
            'ec_id' => $this->ecId,
            'category' => 'CROP',
            'assessed_amount' => '3000.00',
            'approved_amount' => '2500.00',
            'status' => 'APPROVED',
        ]);

        // Add CROP line item
        DB::table('ec_line_item')->insert([
            'ec_id' => $this->ecId,
            'category' => 'CROP',
            'assessed_amount' => '3000.00',
        ]);

        $voucherId = $this->createVoucher();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/voucher/{$voucherId}/compensation", [
                'compensation_id' => $comp2Id,
            ]);

        $response->assertOk();
        // Voucher total should be updated: 8000 + 2500 = 10500
        $this->assertDatabaseHas('voucher', [
            'voucher_id' => $voucherId,
            'approved_amount' => '10500.00',
        ]);
    }

    public function test_attach_already_attached_compensation_rejected(): void
    {
        $voucherId = $this->createVoucher();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/voucher/{$voucherId}/compensation", [
                'compensation_id' => $this->compensationId,
            ]);

        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════
    // PAYMENT — STORE
    // ═══════════════════════════════════════════════════════════════

    public function test_make_partial_payment(): void
    {
        $voucherId = $this->createVoucher();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/voucher/{$voucherId}/payment", [
                'payment_method_id' => 1, // CASH
                'amount' => '3000.00',
                'payment_date' => now()->toDateString(),
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.amount', '3000.00');

        // Voucher should be PARTIAL
        $this->assertDatabaseHas('voucher', [
            'voucher_id' => $voucherId,
            'payment_status' => 'PARTIAL',
        ]);
    }

    public function test_make_full_payment(): void
    {
        $voucherId = $this->createVoucher();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/voucher/{$voucherId}/payment", [
                'payment_method_id' => 1,
                'amount' => '8000.00',
                'payment_date' => now()->toDateString(),
            ])
            ->assertCreated();

        $this->assertDatabaseHas('voucher', [
            'voucher_id' => $voucherId,
            'payment_status' => 'PAID',
        ]);
    }

    public function test_payment_exceeds_voucher_rejected(): void
    {
        $voucherId = $this->createVoucher();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/voucher/{$voucherId}/payment", [
                'payment_method_id' => 1,
                'amount' => '9000.00', // exceeds 8000
                'payment_date' => now()->toDateString(),
            ]);

        $response->assertStatus(422);
    }

    public function test_sum_payments_exceeds_voucher_rejected(): void
    {
        $voucherId = $this->createVoucher();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/voucher/{$voucherId}/payment", [
                'payment_method_id' => 1,
                'amount' => '5000.00',
                'payment_date' => now()->toDateString(),
            ])
            ->assertCreated();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/voucher/{$voucherId}/payment", [
                'payment_method_id' => 1,
                'amount' => '4000.00', // 5000 + 4000 = 9000 > 8000
                'payment_date' => now()->toDateString(),
            ]);

        $response->assertStatus(422);
    }

    public function test_payment_against_cancelled_voucher_rejected(): void
    {
        $voucherId = $this->createVoucher();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/voucher/{$voucherId}/cancel")
            ->assertOk();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/voucher/{$voucherId}/payment", [
                'payment_method_id' => 1,
                'amount' => '1000.00',
                'payment_date' => now()->toDateString(),
            ]);

        $response->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════
    // PAYMENT — VOID
    // ═══════════════════════════════════════════════════════════════

    public function test_void_payment_recalculates_status(): void
    {
        $voucherId = $this->createVoucher();

        // Make full payment
        $paymentId = $this->makePayment($voucherId, 8000.00);
        $this->assertDatabaseHas('voucher', ['voucher_id' => $voucherId, 'payment_status' => 'PAID']);

        // Void it
        $response = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->deleteJson("/api/v1/ep/{$this->epId}/voucher/{$voucherId}/payment/{$paymentId}");

        $response->assertOk();
        $this->assertDatabaseHas('voucher', ['voucher_id' => $voucherId, 'payment_status' => 'PENDING']);
    }

    public function test_void_one_of_many_payments(): void
    {
        $voucherId = $this->createVoucher();

        $pay1 = $this->makePayment($voucherId, 5000.00);
        $this->assertDatabaseHas('voucher', ['voucher_id' => $voucherId, 'payment_status' => 'PARTIAL']);

        $pay2 = $this->makePayment($voucherId, 3000.00);
        $this->assertDatabaseHas('voucher', ['voucher_id' => $voucherId, 'payment_status' => 'PAID']);

        // Void second payment
        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->deleteJson("/api/v1/ep/{$this->epId}/voucher/{$voucherId}/payment/{$pay2}")
            ->assertOk();

        $this->assertDatabaseHas('voucher', ['voucher_id' => $voucherId, 'payment_status' => 'PARTIAL']);
    }

    // ═══════════════════════════════════════════════════════════════
    // VOUCHER — STATUS HISTORY
    // ═══════════════════════════════════════════════════════════════

    public function test_voucher_status_history_recorded(): void
    {
        $voucherId = $this->createVoucher();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/voucher/{$voucherId}/verify")
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/ep/{$this->epId}/voucher/{$voucherId}/approve")
            ->assertOk();

        $historyCount = DB::table('status_history')
            ->where('entity_type', 'VOUCHER')
            ->where('entity_id', $voucherId)
            ->count();

        $this->assertGreaterThanOrEqual(2, $historyCount); // verify + approve
    }

    // ═══════════════════════════════════════════════════════════════
    // AUTH
    // ═══════════════════════════════════════════════════════════════

    public function test_unauthenticated_gets_401(): void
    {
        $response = $this->getJson("/api/v1/ep/{$this->epId}/voucher");

        $response->assertStatus(401);
    }

    public function test_no_permission_gets_403(): void
    {
        $noPermUserId = DB::table('users')->insertGetId([
            'username' => 'noperm_'.Str::random(8),
            'full_name' => 'No Perm',
            'password_hash' => bcrypt('pass'),
            'is_active' => 1,
        ]);

        $noPermToken = Str::random(60);
        DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $noPermUserId,
            'name' => 'test',
            'token' => hash('sha256', $noPermToken),
            'abilities' => json_encode(['*']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$noPermToken)
            ->getJson("/api/v1/ep/{$this->epId}/voucher");

        $response->assertStatus(403);
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function createVoucher(): int
    {
        $voucherId = DB::table('voucher')->insertGetId([
            'ec_id' => $this->ecId,
            'voucher_number' => 'VOU-'.now()->format('Ymd').'-'.rand(1000, 9999),
            'voucher_date' => now()->toDateString(),
            'approved_amount' => '8000.00',
            'payment_status' => 'PENDING',
            'prepared_by' => $this->userId,
        ]);

        // Also attach the approved compensation (mirrors store() behavior)
        DB::table('voucher_compensation')->insert([
            'voucher_id' => $voucherId,
            'compensation_id' => $this->compensationId,
            'amount' => '8000.00',
        ]);

        return $voucherId;
    }

    private function makePayment(int $voucherId, float $amount): int
    {
        $paymentId = DB::table('payment')->insertGetId([
            'voucher_id' => $voucherId,
            'payment_method_id' => 1,
            'amount' => number_format($amount, 2, '.', ''),
            'payment_date' => now()->toDateString(),
        ]);

        // Recalculate payment_status (mirrors PaymentController logic)
        $totalPaid = DB::table('payment')->where('voucher_id', $voucherId)->sum('amount');
        $voucher = DB::table('voucher')->where('voucher_id', $voucherId)->first();
        $approvedAmount = (float) $voucher->approved_amount;

        if ($totalPaid <= 0) {
            $newStatus = 'PENDING';
        } elseif ($totalPaid >= $approvedAmount) {
            $newStatus = 'PAID';
        } else {
            $newStatus = 'PARTIAL';
        }

        DB::table('voucher')->where('voucher_id', $voucherId)->update(['payment_status' => $newStatus]);

        return $paymentId;
    }
}
