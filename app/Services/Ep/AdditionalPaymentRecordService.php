<?php

namespace App\Services\Ep;

use App\Exceptions\BusinessRuleException;
use App\Models\AdditionalPaymentRecord;
use App\Models\AffectedPerson;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Centralizes the second-payment lifecycle for all EP child record types.
 */
class AdditionalPaymentRecordService
{
    public const FIRST_PAYMENT_LABEL = 'Paid in 1st Payment';

    public const SECOND_PAYMENT_LABEL = 'Paid in 2nd Payment';

    /**
     * Register a row created while an EP is already paid. Rows created for an
     * original EP intentionally have no metadata and remain immutable.
     */
    public function register(Model $record, AffectedPerson $ep): void
    {
        if ($ep->status !== 'PAID') {
            return;
        }

        $voucherId = $ep->ec?->vouchers()
            ->where('payment_status', 'PENDING')
            ->whereNull('approved_by')
            ->latest('voucher_id')
            ->value('voucher_id');

        AdditionalPaymentRecord::firstOrCreate(
            [
                'record_type' => $record->getTable(),
                'record_id' => $record->getKey(),
            ],
            [
                'affected_person_id' => $ep->affected_person_id,
                'voucher_id' => $voucherId,
                'payment_cycle' => 2,
                'locked_at' => null,
            ],
        );
    }

    /**
     * Permit normal draft editing, or an unlocked second-payment row for a
     * paid EP. This never permits editing an original row.
     */
    public function assertEditable(AffectedPerson $ep, Model $record, string $entityName): void
    {
        if ($ep->canBeEditedBy(request()->user())) {
            return;
        }

        $tracking = $this->forRecord($record);
        if ($ep->status === 'PAID' && $tracking && $tracking->locked_at === null) {
            return;
        }

        throw new BusinessRuleException(
            'This '.$entityName.' is locked after payment approval.',
            'EP_RECORD_LOCKED',
        );
    }

    /**
     * Add stable display/action metadata to a list of records. An absent
     * tracking row is the authoritative first-payment/original state.
     */
    public function decorate(Collection $records, string $recordType): Collection
    {
        if ($records->isEmpty()) {
            return $records;
        }

        $tracking = AdditionalPaymentRecord::where('record_type', $recordType)
            ->whereIn('record_id', $records->pluck($records->first()->getKeyName()))
            ->get()
            ->keyBy('record_id');

        return $records->each(function (Model $record) use ($tracking): void {
            $metadata = $tracking->get($record->getKey());
            $additional = $metadata !== null;

            $record->setAttribute('payment_cycle', $additional ? 2 : 1);
            $record->setAttribute(
                'payment_cycle_label',
                $additional ? self::SECOND_PAYMENT_LABEL : self::FIRST_PAYMENT_LABEL,
            );
            $record->setAttribute('is_locked', ! $additional || $metadata->locked_at !== null);
            $record->setAttribute('additional_payment_voucher_id', $metadata?->voucher_id);
            $record->setAttribute('locked_at', $metadata?->locked_at);
        });
    }

    public function attachPendingToVoucher(Voucher $voucher): void
    {
        $epId = $voucher->ec?->affected_person_id;
        if (! $epId) {
            return;
        }

        AdditionalPaymentRecord::where('affected_person_id', $epId)
            ->whereNull('voucher_id')
            ->whereNull('locked_at')
            ->update(['voucher_id' => $voucher->voucher_id]);
    }

    public function lockVoucherRecords(Voucher $voucher): void
    {
        AdditionalPaymentRecord::where('voucher_id', $voucher->voucher_id)
            ->whereNull('locked_at')
            ->update(['locked_at' => now()]);
    }

    private function forRecord(Model $record): ?AdditionalPaymentRecord
    {
        return AdditionalPaymentRecord::where('record_type', $record->getTable())
            ->where('record_id', $record->getKey())
            ->first();
    }
}
