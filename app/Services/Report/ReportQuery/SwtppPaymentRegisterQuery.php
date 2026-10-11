<?php

namespace App\Services\Report\ReportQuery;

use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/** SWTPP-compatible cheque/payment register based on the auditable MIS payment ledger. */
class SwtppPaymentRegisterQuery extends AbstractReportQuery
{
    public function execute(): array
    {
        $query = Payment::query()->select([
            'payment.payment_id', 'affected_person.ep_id', DB::raw('affected_person.name AS ep_name'),
            'voucher.voucher_number', 'payment.payment_reference AS cheque_no',
            'payment.payment_date AS cheque_date', DB::raw('payment_method.name AS payment_method'),
            'payment.amount', 'voucher.payment_status',
        ])->join('voucher', 'voucher.voucher_id', '=', 'payment.voucher_id')
            ->join('ec', 'ec.ec_id', '=', 'voucher.ec_id')
            ->join('affected_person', 'affected_person.affected_person_id', '=', 'ec.affected_person_id')
            ->join('payment_method', 'payment_method.payment_method_id', '=', 'payment.payment_method_id');
        $this->applyProjectFilter($query, 'affected_person.project_id');
        $this->applyDistrictFilter($query, 'affected_person.district_id');
        $this->applyUpazilaFilter($query, 'affected_person.upazila_id');
        $this->applyDateRangeFilter($query, 'payment.payment_date');
        $rows = $query->orderBy('payment.payment_date')->orderBy('payment.payment_id')->get()->map(function (Payment $model): array {
            $row = $model->getAttributes();
            $row['amount'] = (string) $row['amount'];

            return $row;
        })->all();

        return ['title' => 'SWTPP Payment Register', 'columns' => $this->columns(), 'rows' => $rows,
            'summary' => ['total_payments' => count($rows), 'total_paid' => number_format(array_sum(array_map(fn ($row) => (float) $row['amount'], $rows)), 2, '.', '')]];
    }

    protected function columns(): array
    {
        return [
            ['key' => 'payment_id', 'label' => 'Payment ID', 'type' => 'integer'],
            ['key' => 'ep_id', 'label' => 'EP ID', 'type' => 'string'],
            ['key' => 'ep_name', 'label' => 'EP Name', 'type' => 'string'],
            ['key' => 'voucher_number', 'label' => 'Voucher No', 'type' => 'string'],
            ['key' => 'cheque_no', 'label' => 'Cheque No', 'type' => 'string'],
            ['key' => 'cheque_date', 'label' => 'Cheque Date', 'type' => 'date'],
            ['key' => 'payment_method', 'label' => 'Payment Method', 'type' => 'string'],
            ['key' => 'amount', 'label' => 'Paid Amount', 'type' => 'money'],
            ['key' => 'payment_status', 'label' => 'Payment Status', 'type' => 'string'],
        ];
    }
}
