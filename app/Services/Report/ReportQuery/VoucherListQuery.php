<?php

namespace App\Services\Report\ReportQuery;

use App\Models\Voucher;
use Illuminate\Support\Facades\DB;

/**
 * R15 — Voucher List.
 *
 * Full listing of all vouchers with EC and affected person details,
 * payment status, and preparer/verifier/approver information.
 *
 * Source: voucher → ec → affected_person
 *         LEFT JOIN users (prepared_by, verified_by, approved_by)
 */
class VoucherListQuery extends AbstractReportQuery
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
                DB::raw('prepared_user.full_name AS prepared_by_name'),
                DB::raw('verified_user.full_name AS verified_by_name'),
                DB::raw('approved_user.full_name AS approved_by_name'),
            ])
            ->join('ec', 'ec.ec_id', '=', 'voucher.ec_id')
            ->join('affected_person', 'affected_person.affected_person_id', '=', 'ec.affected_person_id')
            ->leftJoin('users AS prepared_user', 'prepared_user.user_id', '=', 'voucher.prepared_by')
            ->leftJoin('users AS verified_user', 'verified_user.user_id', '=', 'voucher.verified_by')
            ->leftJoin('users AS approved_user', 'approved_user.user_id', '=', 'voucher.approved_by');

        $this->applyProjectFilter($query, 'affected_person.project_id');
        $this->applyDistrictFilter($query, 'affected_person.district_id');
        $this->applyUpazilaFilter($query, 'affected_person.upazila_id');

        // payment_status filter (not generic status).
        if (! empty($this->filters['payment_status'])) {
            $query->where('voucher.payment_status', $this->filters['payment_status']);
        }

        $query->orderBy('voucher.voucher_id');

        $rows = $query->get()->toArray();

        foreach ($rows as &$row) {
            $row['approved_amount'] = (string) $row['approved_amount'];
            // Rename alias back to expected column key.
            $row['voucher_date'] = $row['voucher_date_str'] ?? null;
            unset($row['voucher_date_str']);
        }
        unset($row);

        return [
            'title' => 'Voucher List',
            'columns' => $this->columns(),
            'rows' => $rows,
            'summary' => [
                'total_vouchers' => count($rows),
                'total_amount' => number_format(
                    array_sum(array_map(fn ($r) => (float) $r['approved_amount'], $rows)),
                    2, '.', '',
                ),
            ],
        ];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'voucher_id',          'label' => 'Voucher ID',          'type' => 'integer'],
            ['key' => 'voucher_number',       'label' => 'Voucher Number',      'type' => 'string'],
            ['key' => 'voucher_date',         'label' => 'Voucher Date',        'type' => 'date'],
            ['key' => 'approved_amount',      'label' => 'Approved Amount',     'type' => 'money'],
            ['key' => 'payment_status',       'label' => 'Payment Status',      'type' => 'string'],
            ['key' => 'ec_number',            'label' => 'EC Number',           'type' => 'string'],
            ['key' => 'ep_id',                'label' => 'EP ID',              'type' => 'string'],
            ['key' => 'ep_name',              'label' => 'EP Name',            'type' => 'string'],
            ['key' => 'prepared_by_name',     'label' => 'Prepared By',        'type' => 'string'],
            ['key' => 'verified_by_name',     'label' => 'Verified By',        'type' => 'string'],
            ['key' => 'approved_by_name',     'label' => 'Approved By',        'type' => 'string'],
        ];
    }
}
