<?php

namespace App\Services\Ep;

use App\Models\AffectedPerson;
use App\Models\Ec;
use App\Models\Voucher;
use Illuminate\Support\Facades\DB;

/**
 * Removes every descendant row of an affected person.
 *
 * Shared by the normal DRAFT delete (EpController) and the admin correction
 * delete (EpCorrectionController). Child tables reference affected_person with
 * ON DELETE RESTRICT, so children must be removed themselves. The walk is
 * deepest-first so every FK resolves before its parent is removed.
 *
 * NOTE: `correction_audit` rows are deliberately NOT removed — they are the
 * permanent record of why an admin corrected or deleted the EP.
 */
class EpDeletionService
{
    /**
     * True when the EP has any recorded disbursement. Correction deletes are
     * refused in that case so financial history is never destroyed.
     */
    public function hasPayments(AffectedPerson $ep): bool
    {
        $ec = Ec::where('affected_person_id', $ep->affected_person_id)->first();

        if (! $ec) {
            return false;
        }

        return DB::table('payment')
            ->whereIn('voucher_id', function ($query) use ($ec): void {
                $query->select('voucher_id')
                    ->from('voucher')
                    ->where('ec_id', $ec->ec_id);
            })
            ->exists();
    }

    public function deleteGraph(int $epId): void
    {
        // Deepest leaves under land / tree / infrastructure / crop / business /
        // worker / ec / voucher first.
        DB::table('crop_assessment')->whereIn('crop_id', function ($q) use ($epId) {
            $q->select('crop_id')->from('crop')->where('land_id', function ($q2) use ($epId) {
                $q2->select('land_id')->from('land')->where('affected_person_id', $epId);
            });
        })->delete();

        DB::table('land_assessment')->whereIn('land_id', function ($q) use ($epId) {
            $q->select('land_id')->from('land')->where('affected_person_id', $epId);
        })->delete();

        DB::table('crop')->whereIn('land_id', function ($q) use ($epId) {
            $q->select('land_id')->from('land')->where('affected_person_id', $epId);
        })->delete();

        DB::table('tree_assessment')->whereIn('tree_id', function ($q) use ($epId) {
            $q->select('tree_id')->from('tree')->where('affected_person_id', $epId);
        })->delete();

        DB::table('infrastructure_assessment')->whereIn('infrastructure_id', function ($q) use ($epId) {
            $q->select('infrastructure_id')->from('infrastructure')->where('affected_person_id', $epId);
        })->delete();

        DB::table('worker_assessment')->whereIn('worker_id', function ($q) use ($epId) {
            $q->select('worker_id')->from('worker')->where('affected_person_id', $epId);
        })->delete();

        DB::table('business_loss')->whereIn('business_id', function ($q) use ($epId) {
            $q->select('business_id')->from('business')->where('affected_person_id', $epId);
        })->delete();

        // Workers may reference businesses and tenants; remove before those.
        DB::table('worker')->where('affected_person_id', $epId)->delete();

        // Payments and voucher_compensation hang off voucher, which hangs off ec.
        DB::table('payment')->whereIn('voucher_id', function ($q) use ($epId) {
            $q->select('voucher_id')->from('voucher')->where('ec_id', function ($q2) use ($epId) {
                $q2->select('ec_id')->from('ec')->where('affected_person_id', $epId);
            });
        })->delete();

        DB::table('voucher_compensation')->whereIn('voucher_id', function ($q) use ($epId) {
            $q->select('voucher_id')->from('voucher')->where('ec_id', function ($q2) use ($epId) {
                $q2->select('ec_id')->from('ec')->where('affected_person_id', $epId);
            });
        })->delete();

        DB::table('voucher')->whereIn('ec_id', function ($q) use ($epId) {
            $q->select('ec_id')->from('ec')->where('affected_person_id', $epId);
        })->delete();

        DB::table('compensation')->whereIn('ec_id', function ($q) use ($epId) {
            $q->select('ec_id')->from('ec')->where('affected_person_id', $epId);
        })->delete();

        DB::table('ec_line_item')->whereIn('ec_id', function ($q) use ($epId) {
            $q->select('ec_id')->from('ec')->where('affected_person_id', $epId);
        })->delete();

        DB::table('ec')->where('affected_person_id', $epId)->delete();
        DB::table('epicad')->where('affected_person_id', $epId)->delete();

        // Additional-payment metadata cascades on affected_person, but its
        // voucher_id FK is nullOnDelete, so clear it before vouchers vanish.
        DB::table('additional_payment_record')->where('affected_person_id', $epId)->delete();

        // Direct children of the EP.
        DB::table('family_member')->where('affected_person_id', $epId)->delete();
        DB::table('land')->where('affected_person_id', $epId)->delete();
        DB::table('tree')->where('affected_person_id', $epId)->delete();
        DB::table('infrastructure')->where('affected_person_id', $epId)->delete();
        DB::table('tenant')->where('affected_person_id', $epId)->delete();
        DB::table('business')->where('affected_person_id', $epId)->delete();
        DB::table('allowance')->where('affected_person_id', $epId)->delete();

        // Polymorphic audit trail for this EP.
        DB::table('status_history')
            ->where('entity_type', 'EP')
            ->where('entity_id', $epId)
            ->delete();

        DB::table('affected_person')->where('affected_person_id', $epId)->delete();
    }
}
