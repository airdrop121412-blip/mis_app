<?php

namespace App\Http\Controllers\Api\V1\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AffectedPerson;
use App\Models\Compensation;
use App\Models\Ec;
use App\Models\Epicad;
use App\Models\Payment;
use App\Models\Voucher;
use App\Services\Validation\ValidationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard statistics API.
 *
 * Returns aggregate counts for the MIS dashboard.
 * No business logic here — pure aggregate queries.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly ValidationService $validationService,
    ) {}

    /**
     * GET /api/v1/dashboard/stats
     *
     * Returns overview statistics, optionally scoped by project_id.
     */
    public function stats(Request $request): JsonResponse
    {
        $projectId = $request->input('project_id');

        $epQuery = AffectedPerson::query();
        $ecQuery = Ec::query();
        $epicadQuery = Epicad::query();
        $compensationQuery = Compensation::query();
        $voucherQuery = Voucher::query();
        $paymentQuery = Payment::query();

        if ($projectId) {
            $epQuery->where('project_id', $projectId);
            // EC, EPICAD, etc. join back to affected_person for project scoping
        }

        // ── EP counts by status ───────────────────────────────
        $epByStatus = (clone $epQuery)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $totalEps = array_sum($epByStatus);

        // ── EC counts by status ───────────────────────────────
        if ($projectId) {
            $ecByStatus = (clone $ecQuery)
                ->join('affected_person', 'ec.affected_person_id', '=', 'affected_person.affected_person_id')
                ->where('affected_person.project_id', $projectId)
                ->select('ec.status', DB::raw('count(*) as count'))
                ->groupBy('ec.status')
                ->pluck('count', 'status')
                ->toArray();
        } else {
            $ecByStatus = (clone $ecQuery)
                ->select('status', DB::raw('count(*) as count'))
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray();
        }
        $totalEcs = array_sum($ecByStatus);

        // ── EPICAD count ──────────────────────────────────────
        if ($projectId) {
            $totalEpicads = (clone $epicadQuery)
                ->join('affected_person', 'epicad.affected_person_id', '=', 'affected_person.affected_person_id')
                ->where('affected_person.project_id', $projectId)
                ->count();
        } else {
            $totalEpicads = (clone $epicadQuery)->count();
        }

        // ── Voucher counts ────────────────────────────────────
        if ($projectId) {
            $voucherStats = (clone $voucherQuery)
                ->join('ec', 'voucher.ec_id', '=', 'ec.ec_id')
                ->join('affected_person', 'ec.affected_person_id', '=', 'affected_person.affected_person_id')
                ->where('affected_person.project_id', $projectId)
                ->select('voucher.payment_status', DB::raw('count(*) as count'), DB::raw('COALESCE(SUM(approved_amount), 0) as total_amount'))
                ->groupBy('voucher.payment_status')
                ->get();
        } else {
            $voucherStats = (clone $voucherQuery)
                ->select('payment_status', DB::raw('count(*) as count'), DB::raw('COALESCE(SUM(approved_amount), 0) as total_amount'))
                ->groupBy('payment_status')
                ->get();
        }

        $voucherByStatus = $voucherStats->pluck('count', 'payment_status')->toArray();
        $totalVouchers = array_sum($voucherByStatus);
        $totalVoucherAmount = $voucherStats->sum('total_amount');

        // ── Payment totals ────────────────────────────────────
        if ($projectId) {
            $paymentTotals = (clone $paymentQuery)
                ->join('voucher', 'payment.voucher_id', '=', 'voucher.voucher_id')
                ->join('ec', 'voucher.ec_id', '=', 'ec.ec_id')
                ->join('affected_person', 'ec.affected_person_id', '=', 'affected_person.affected_person_id')
                ->where('affected_person.project_id', $projectId)
                ->select(DB::raw('COALESCE(SUM(amount), 0) as total_paid'), DB::raw('count(*) as payment_count'))
                ->first();
        } else {
            $paymentTotals = (clone $paymentQuery)
                ->select(DB::raw('COALESCE(SUM(amount), 0) as total_paid'), DB::raw('count(*) as payment_count'))
                ->first();
        }

        // ── Validation summary ────────────────────────────────
        if ($projectId) {
            $validationSummary = $this->validationService->getProjectSummary($projectId);
        } else {
            // For dashboard without project scope, get totals across all projects
                        $invalidEps = $this->validationService->countInvalidEps(null);
            $validationSummary = [
                'total_eps' => $totalEps,
                'invalid_eps' => $invalidEps,
                'valid_eps' => $totalEps,
                'with_epicad' => $totalEpicads,
                'with_ec' => $totalEcs,
                'with_approved_ec' => isset($ecByStatus['APPROVED']) ? $ecByStatus['APPROVED'] : 0,
                'with_voucher' => $totalVouchers,
            ];
        }

        return ApiResponse::success([
            'ep' => [
                'total' => $totalEps,
                'by_status' => $epByStatus,
            ],
            'epicad' => [
                'total' => $totalEpicads,
            ],
            'ec' => [
                'total' => $totalEcs,
                'by_status' => $ecByStatus,
            ],
            'compensation' => [
                'total_approved' => isset($ecByStatus['APPROVED']) ? $ecByStatus['APPROVED'] : 0,
            ],
            'voucher' => [
                'total' => $totalVouchers,
                'by_status' => $voucherByStatus,
                'total_amount' => (string) $totalVoucherAmount,
            ],
            'payment' => [
                'total_count' => $paymentTotals->payment_count ?? 0,
                'total_paid' => (string) ($paymentTotals->total_paid ?? 0),
            ],
            'validation' => $validationSummary,
        ]);
    }
}
