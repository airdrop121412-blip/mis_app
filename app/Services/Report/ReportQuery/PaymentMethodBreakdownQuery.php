<?php

namespace App\Services\Report\ReportQuery;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * R18 — Payment Method Breakdown.
 *
 * Aggregated payment amounts grouped by payment method.
 * Shows the distribution of payments across CASH, BANK, BKASH, etc.
 *
 * Source: payment → payment_method, voucher → ec → affected_person
 */
class PaymentMethodBreakdownQuery extends AbstractReportQuery
{
    public function execute(): array
    {
        $query = Payment::query()
            ->select([
                DB::raw('payment_method.name AS payment_method'),
                DB::raw('COUNT(*) AS payment_count'),
                DB::raw('SUM(payment.amount) AS total_amount'),
            ])
            ->join('payment_method', 'payment_method.payment_method_id', '=', 'payment.payment_method_id')
            ->join('voucher', 'voucher.voucher_id', '=', 'payment.voucher_id')
            ->join('ec', 'ec.ec_id', '=', 'voucher.ec_id');

        $this->applyGeoFilter($query);

        $query->groupBy('payment_method.payment_method_id', 'payment_method.name')
            ->orderBy('total_amount', 'desc');

        $rows = $query->get()->toArray();

        $totalPaid = array_sum(array_map(fn ($r) => (float) $r['total_amount'], $rows));
        $totalCount = array_sum(array_map(fn ($r) => (int) $r['payment_count'], $rows));

        foreach ($rows as &$row) {
            $row['total_amount'] = (string) $row['total_amount'];
            $row['percentage'] = $totalPaid > 0
                ? round(((float) $row['total_amount'] / $totalPaid) * 100, 2)
                : '0.00';
        }
        unset($row);

        return [
            'title' => 'Payment Method Breakdown',
            'columns' => $this->columns(),
            'rows' => $rows,
            'summary' => [
                'total_payments' => $totalCount,
                'total_amount' => number_format($totalPaid, 2, '.', ''),
            ],
        ];
    }

    private function applyGeoFilter(Builder $query): void
    {
        $needsApJoin = ! empty($this->filters['project_id'])
            || ! empty($this->filters['district_id'])
            || ! empty($this->filters['upazila_id']);

        if ($needsApJoin) {
            $query->join('affected_person', 'affected_person.affected_person_id', '=', 'ec.affected_person_id');
        }

        if (! empty($this->filters['project_id'])) {
            $query->where('affected_person.project_id', $this->filters['project_id']);
        }
        if (! empty($this->filters['district_id'])) {
            $query->where('affected_person.district_id', $this->filters['district_id']);
        }
        if (! empty($this->filters['upazila_id'])) {
            $query->where('affected_person.upazila_id', $this->filters['upazila_id']);
        }
    }

    protected function columns(): array
    {
        return [
            ['key' => 'payment_method',  'label' => 'Payment Method',  'type' => 'string'],
            ['key' => 'payment_count',   'label' => 'Payment Count',   'type' => 'integer'],
            ['key' => 'total_amount',    'label' => 'Total Amount',    'type' => 'money'],
            ['key' => 'percentage',      'label' => 'Percentage',      'type' => 'percentage'],
        ];
    }
}
