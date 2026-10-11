<?php

namespace App\Services\Report\ReportQuery;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * R16 — Payment Summary.
 *
 * Aggregated payment amounts grouped by payment month.
 * Provides a monthly disbursement timeline.
 *
 * Source: payment → voucher → ec → affected_person
 */
class PaymentSummaryQuery extends AbstractReportQuery
{
    public function execute(): array
    {
        $query = Payment::query()
            ->select([
                DB::raw('DATE_FORMAT(payment.payment_date, "%Y-%m") AS payment_month'),
                DB::raw('COUNT(*) AS payment_count'),
                DB::raw('SUM(payment.amount) AS total_amount'),
            ])
            ->join('voucher', 'voucher.voucher_id', '=', 'payment.voucher_id')
            ->join('ec', 'ec.ec_id', '=', 'voucher.ec_id');

        $this->applyGeoFilter($query);
        $this->applyDateRangeFilter($query, 'payment.payment_date');

        $query->groupBy(DB::raw('DATE_FORMAT(payment.payment_date, "%Y-%m")'))
            ->orderBy('payment_month', 'desc');

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
            'title' => 'Payment Summary',
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
            ['key' => 'payment_month',  'label' => 'Payment Month',  'type' => 'string'],
            ['key' => 'payment_count',  'label' => 'Payment Count',  'type' => 'integer'],
            ['key' => 'total_amount',   'label' => 'Total Amount',   'type' => 'money'],
            ['key' => 'percentage',     'label' => 'Percentage',     'type' => 'percentage'],
        ];
    }
}
