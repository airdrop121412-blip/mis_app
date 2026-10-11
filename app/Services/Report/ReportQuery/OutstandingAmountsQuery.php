<?php

namespace App\Services\Report\ReportQuery;

use App\Models\Voucher;
use Illuminate\Support\Facades\DB;

/**
 * R17 — Outstanding Amounts.
 *
 * Per-voucher outstanding amounts: approved_amount minus total paid.
 * Identifies vouchers with unpaid or partially paid balances.
 *
 * Source: voucher → ec → affected_person
 *         LEFT JOIN payment (aggregated)
 */
class OutstandingAmountsQuery extends AbstractReportQuery
{
    public function execute(): array
    {
        $query = Voucher::query()
            ->select([
                'voucher.voucher_id',
                'voucher.voucher_number',
                DB::raw('DATE(voucher.voucher_date) AS voucher_date_str'),
                'voucher.approved_amount',
                'voucher.payment_status',
                'ec.ec_number',
                'affected_person.ep_id',
                'affected_person.name AS ep_name',
                DB::raw('COALESCE(paid.total_paid, 0) AS total_paid'),
                DB::raw('voucher.approved_amount - COALESCE(paid.total_paid, 0) AS outstanding'),
            ])
            ->join('ec', 'ec.ec_id', '=', 'voucher.ec_id')
            ->join('affected_person', 'affected_person.affected_person_id', '=', 'ec.affected_person_id')
            ->leftJoinSub(
                DB::table('payment')
                    ->select('voucher_id', DB::raw('SUM(amount) AS total_paid'))
                    ->groupBy('voucher_id'),
                'paid',
                'paid.voucher_id',
                '=',
                'voucher.voucher_id',
            );

        $this->applyProjectFilter($query, 'affected_person.project_id');
        $this->applyDistrictFilter($query, 'affected_person.district_id');
        $this->applyUpazilaFilter($query, 'affected_person.upazila_id');

        // Filter for outstanding only (non-zero outstanding amounts).
        // Uses whereRaw (not havingRaw) because this is a per-voucher listing,
        // not an aggregated query — there is no GROUP BY.
        if (! empty($this->filters['outstanding_only']) && $this->filters['outstanding_only'] === '1') {
            $query->whereRaw('voucher.approved_amount - COALESCE(paid.total_paid, 0) > 0');
        }

        $query->orderBy('voucher.voucher_id');

        $rows = $query->get()->toArray();

        foreach ($rows as &$row) {
            $row['approved_amount'] = (string) $row['approved_amount'];
            $row['total_paid'] = (string) $row['total_paid'];
            $row['outstanding'] = (string) $row['outstanding'];
            // Rename alias back to expected column key.
            $row['voucher_date'] = $row['voucher_date_str'] ?? null;
            unset($row['voucher_date_str']);
        }
        unset($row);

        $totalOutstanding = array_sum(array_map(fn ($r) => (float) $r['outstanding'], $rows));

        return [
            'title' => 'Outstanding Amounts',
            'columns' => $this->columns(),
            'rows' => $rows,
            'summary' => [
                'total_vouchers' => count($rows),
                'total_outstanding' => number_format($totalOutstanding, 2, '.', ''),
            ],
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'voucher_id',       'label' => 'Voucher ID',       'type' => 'integer'],
            ['key' => 'voucher_number',    'label' => 'Voucher Number',   'type' => 'string'],
            ['key' => 'voucher_date',      'label' => 'Voucher Date',     'type' => 'date'],
            ['key' => 'approved_amount',   'label' => 'Approved Amount',  'type' => 'money'],
            ['key' => 'total_paid',        'label' => 'Total Paid',       'type' => 'money'],
            ['key' => 'outstanding',       'label' => 'Outstanding',      'type' => 'money'],
            ['key' => 'payment_status',    'label' => 'Payment Status',   'type' => 'string'],
            ['key' => 'ec_number',         'label' => 'EC Number',        'type' => 'string'],
            ['key' => 'ep_id',             'label' => 'EP ID',            'type' => 'string'],
            ['key' => 'ep_name',           'label' => 'EP Name',          'type' => 'string'],
        ];
    }
}
