<?php

namespace App\Services\Ep;

use App\Models\AdditionalPaymentRecord;
use App\Models\AffectedPerson;
use App\Models\GrantAssistance;
use App\Models\RateFileLand;
use App\Models\RateFileStructure;
use App\Models\RateFileTree;

/**
 * Server-side mirror of the EC File calculation used by Voucher generation.
 */
class EcCalculationService
{
    public const CATEGORIES = [
        'CfL', 'CfS', 'CfT', 'CfSC', 'CfFS', 'RV_STRUCTURE', 'LDC', 'DA',
        'SRC', 'HTG', 'HCG', 'CfF', 'CAVHH', 'AVFHH', 'CfB', 'MTG', 'SGfT', 'GfWL',
    ];

    private const DECIMAL_PER_UNIT = [
        1 => 1, 2 => 33, 3 => 1.6529, 4 => 1, 5 => 1 / 435.6,
        6 => 1 / 40.4686, 7 => 100 / 0.4047, 8 => 100, 9 => 1,
    ];

    public function calculate(AffectedPerson $ep): array
    {
        $ep->loadMissing([
            'lands.assessments', 'lands.landType', 'lands.crops.assessment', 'trees.assessment',
            'trees.treeType', 'trees.treeAgeCategory', 'gender', 'occupation',
            'infrastructures.assessment', 'workers.assessment', 'businesses.loss',
        ]);

        $landRates = RateFileLand::active()->get();
        $structureRates = RateFileStructure::active()->get();
        $treeRates = RateFileTree::active()->get();
        $grants = GrantAssistance::active()->with('rates')->get()->keyBy('grant_code');

        $lands = $ep->lands;
        $structures = $ep->infrastructures;
        $trees = $ep->trees;
        $landQty = 0;
        $pavc = 0;
        $dc = 0;

        foreach ($lands as $land) {
            $qty = (float) $land->land_area * (self::DECIMAL_PER_UNIT[(int) $land->land_area_unit_id] ?? 1);
            $landQty += $qty;
            $rate = $landRates->first(fn ($r) => (string) $r->mouza === (string) $land->mouza
                && (string) $r->land_category === (string) ($land->land_type_other ?: ($land->landType?->name ?? '')));
            $pavc += $rate ? $qty * (float) $rate->pavc_rate : 0;
            $dc += $rate ? $qty * (float) $rate->dc_office_rate : 0;
        }

        $amounts = [
            'CfL' => preg_match('/non/i', (string) $ep->ownership) ? 0 : $pavc - $dc,
            'CfS' => $this->assessmentTotal($structures, 'assessment', 'assessed_loss'),
            'CfT' => (float) $trees->sum(fn ($tree) => (float) ($tree->assessment?->assessed_loss ?? 0)),
            'CfSC' => $this->cropTotal($lands),
            'CfFS' => 0, 'RV_STRUCTURE' => 0,
            'CfF' => $this->treeRateTotal($ep, $trees, $treeRates),
        ];

        $rv = 0;
        $htgBase = 0;
        $hcgBase = 0;
        foreach ($structures as $structure) {
            $material = $structure->structure_material === 'Other'
                ? (string) $structure->structure_material_other
                : (string) $structure->structure_material;
            $rate = $structureRates->first(fn ($r) => ($r->district_id === null || (int) $r->district_id === (int) $ep->district_id)
                && ($r->upazila_id === null || (int) $r->upazila_id === (int) $ep->upazila_id)
                && (string) $r->material === $material
                && (string) $r->usage_type === (string) ($structure->usage_type_other ?: ($structure->usageType?->name ?? ''))
                && (string) $r->nature === (string) $structure->structure_nature
                && (string) $r->unit === (string) ($structure->unit ?: 'SFT'));
            $value = (float) $structure->area_sqft * (float) ($rate?->rate ?? 0);
            $rv += $value;
            if ($structure->structure_nature === 'Primary' && in_array($material, ['Tin Made', 'Katcha', 'Thatched'], true)) {
                $htgBase += $value;
            }
            if ($structure->structure_nature === 'Primary' && in_array($material, ['Pacca', 'Semi Pacca'], true)) {
                $hcgBase += $value;
            }
        }
        $amounts['RV_STRUCTURE'] = $rv;

        foreach ($grants as $code => $grant) {
            $base = $grant->percentage_base === 'RV_STRUCTURE' ? $rv : $pavc;
            $value = match ($grant->calculation_type) {
                'FIXED' => $code === 'CfB'
                    ? (float) ($grant->rates->where('is_active', true)
                        ->first(fn ($r) => $r->district_id !== null && (int) $r->district_id === (int) $ep->district_id)?->amount
                        ?? $grant->rates->where('is_active', true)->first(fn ($r) => $r->district_id === null)?->amount
                        ?? $grant->amount)
                    : (float) $grant->amount,
                'PERCENTAGE' => $base * (float) $grant->percentage / 100,
                'FIXED_AND_PERCENTAGE' => (float) $grant->amount + ($base * (float) $grant->percentage / 100),
                'MULTIPLIER' => $code === 'DA'
                    ? (float) $lands->sum(fn ($land) => $this->daMultiplier($grant, $ep, $land) * $this->decimalArea($land))
                    : $landQty * (float) $grant->multiplier,
                default => 0,
            };
            if ($code === 'HTG' && $htgBase <= 0) {
                $value = 0;
            }
            if ($code === 'HCG' && $hcgBase <= 0) {
                $value = 0;
            }
            if ($code === 'HTG' && $htgBase > 0) {
                $value = $this->grantValue($grant, $htgBase);
            }
            if ($code === 'HCG' && $hcgBase > 0) {
                $value = $this->grantValue($grant, $hcgBase);
            }
            if ($code === 'AVFHH' && ! preg_match('/female/i', (string) ($ep->gender?->name ?? $ep->gender_other))) {
                $value = 0;
            }
            if ($code === 'CAVHH' && (float) $ep->yearly_income >= 90000) {
                $value = 0;
            }
            if ($code === 'SGfT') {
                $value = 0;
            }
            if ($code === 'GfWL' && ! preg_match('/wage\s*labou?r/i', (string) ($ep->occupation?->name ?? $ep->occupation_other))) {
                $value = 0;
            }
            if ($code === 'CfB' && ! $ep->businesses->contains(fn ($b) => strtoupper((string) $b->ownership_code) === 'OWNER')) {
                $value = 0;
            }
            $amounts[$code] = $value;
        }

        $amounts['SRC'] = (float) ($amounts['SRC'] ?? 0) ?: (float) $ep->stamp_duty;

        return array_replace(array_fill_keys(self::CATEGORIES, 0.0), $amounts);
    }

    /**
     * Calculate compensation amounts from ONLY the additional (second-payment)
     * records registered for a PAID EP — i.e. rows tracked in
     * additional_payment_record that are not yet attached to any voucher.
     *
     * Mirrors calculate() but scopes every relation to the newly entered
     * records, and forces one-time EP-level grants (CAVHH, AVFHH, SRC, SGfT)
     * to zero because they were already awarded in the original payment.
     * Original payment data is never touched.
     *
     * @return array<string, float> category => additional amount
     */
    public function calculateAdditional(AffectedPerson $ep): array
    {
        $ep->loadMissing([
            'lands.assessments', 'lands.landType', 'lands.crops.assessment', 'trees.assessment',
            'trees.treeType', 'trees.treeAgeCategory', 'gender', 'occupation',
            'infrastructures.assessment', 'businesses.loss',
        ]);

        $registered = AdditionalPaymentRecord::where('affected_person_id', $ep->affected_person_id)
            ->whereNull('voucher_id')
            ->get(['record_type', 'record_id'])
            ->groupBy('record_type')
            ->map(fn ($rows) => $rows->pluck('record_id')->map(fn ($id) => (int) $id)->all())
            ->all();

        if (empty($registered)) {
            return array_fill_keys(self::CATEGORIES, 0.0);
        }

        $landIds = $registered['land'] ?? [];
        $cropIds = $registered['crop'] ?? [];
        $treeIds = $registered['tree'] ?? [];
        $structureIds = $registered['infrastructure'] ?? [];
        $businessIds = $registered['business'] ?? [];

        $landRates = RateFileLand::active()->get();
        $structureRates = RateFileStructure::active()->get();
        $treeRates = RateFileTree::active()->get();
        $grants = GrantAssistance::active()->with('rates')->get()->keyBy('grant_code');

        // Only NEW (second-payment) records contribute to the additional voucher.
        $lands = $ep->lands->whereIn('land_id', $landIds)->values();
        $structures = $ep->infrastructures->whereIn('infrastructure_id', $structureIds)->values();
        $trees = $ep->trees->whereIn('tree_id', $treeIds)->values();

        $landQty = 0;
        $pavc = 0;
        $dc = 0;

        foreach ($lands as $land) {
            $qty = (float) $land->land_area * (self::DECIMAL_PER_UNIT[(int) $land->land_area_unit_id] ?? 1);
            $landQty += $qty;
            $rate = $landRates->first(fn ($r) => (string) $r->mouza === (string) $land->mouza
                && (string) $r->land_category === (string) ($land->land_type_other ?: ($land->landType?->name ?? '')));
            $pavc += $rate ? $qty * (float) $rate->pavc_rate : 0;
            $dc += $rate ? $qty * (float) $rate->dc_office_rate : 0;
        }

        // Crops on new lands, plus cycle-2 crops entered on pre-existing lands.
        $cropTotal = (float) $lands->flatMap(fn ($land) => $land->crops)
            ->sum(fn ($crop) => (float) ($crop->assessment?->assessed_loss ?? 0));
        if ($cropIds !== []) {
            $cropTotal += (float) $ep->lands->whereNotIn('land_id', $landIds)
                ->flatMap(fn ($land) => $land->crops->whereIn('crop_id', $cropIds))
                ->sum(fn ($crop) => (float) ($crop->assessment?->assessed_loss ?? 0));
        }

        $amounts = [
            'CfL' => preg_match('/non/i', (string) $ep->ownership) ? 0 : $pavc - $dc,
            'CfS' => $this->assessmentTotal($structures, 'assessment', 'assessed_loss'),
            'CfT' => (float) $trees->sum(fn ($tree) => (float) ($tree->assessment?->assessed_loss ?? 0)),
            'CfSC' => $cropTotal,
            'CfFS' => 0, 'RV_STRUCTURE' => 0,
            'CfF' => $this->treeRateTotal($ep, $trees, $treeRates),
        ];

        $rv = 0;
        $htgBase = 0;
        $hcgBase = 0;
        foreach ($structures as $structure) {
            $material = $structure->structure_material === 'Other'
                ? (string) $structure->structure_material_other
                : (string) $structure->structure_material;
            $rate = $structureRates->first(fn ($r) => ($r->district_id === null || (int) $r->district_id === (int) $ep->district_id)
                && ($r->upazila_id === null || (int) $r->upazila_id === (int) $ep->upazila_id)
                && (string) $r->material === $material
                && (string) $r->usage_type === (string) ($structure->usage_type_other ?: ($structure->usageType?->name ?? ''))
                && (string) $r->nature === (string) $structure->structure_nature
                && (string) $r->unit === (string) ($structure->unit ?: 'SFT'));
            $value = (float) $structure->area_sqft * (float) ($rate?->rate ?? 0);
            $rv += $value;
            if ($structure->structure_nature === 'Primary' && in_array($material, ['Tin Made', 'Katcha', 'Thatched'], true)) {
                $htgBase += $value;
            }
            if ($structure->structure_nature === 'Primary' && in_array($material, ['Pacca', 'Semi Pacca'], true)) {
                $hcgBase += $value;
            }
        }
        $amounts['RV_STRUCTURE'] = $rv;

        $newOwnerBusinesses = $ep->businesses->whereIn('business_id', $businessIds)
            ->contains(fn ($b) => strtoupper((string) $b->ownership_code) === 'OWNER');

        foreach ($grants as $code => $grant) {
            $base = $grant->percentage_base === 'RV_STRUCTURE' ? $rv : $pavc;
            $value = match ($grant->calculation_type) {
                'FIXED' => $code === 'CfB'
                    ? (float) ($grant->rates->where('is_active', true)
                        ->first(fn ($r) => $r->district_id !== null && (int) $r->district_id === (int) $ep->district_id)?->amount
                        ?? $grant->rates->where('is_active', true)->first(fn ($r) => $r->district_id === null)?->amount
                        ?? $grant->amount)
                    : (float) $grant->amount,
                'PERCENTAGE' => $base * (float) $grant->percentage / 100,
                'FIXED_AND_PERCENTAGE' => (float) $grant->amount + ($base * (float) $grant->percentage / 100),
                'MULTIPLIER' => $code === 'DA'
                    ? (float) $lands->sum(fn ($land) => $this->daMultiplier($grant, $ep, $land) * $this->decimalArea($land))
                    : $landQty * (float) $grant->multiplier,
                default => 0,
            };
            if ($code === 'HTG' && $htgBase <= 0) {
                $value = 0;
            }
            if ($code === 'HCG' && $hcgBase <= 0) {
                $value = 0;
            }
            if ($code === 'HTG' && $htgBase > 0) {
                $value = $this->grantValue($grant, $htgBase);
            }
            if ($code === 'HCG' && $hcgBase > 0) {
                $value = $this->grantValue($grant, $hcgBase);
            }
            // One-time EP-level grants were already awarded in the original payment.
            if (in_array($code, ['CAVHH', 'AVFHH', 'SRC', 'SGfT'], true)) {
                $value = 0;
            }
            // Wage-loss grant is not part of additional payments.
            if ($code === 'GfWL') {
                $value = 0;
            }
            if ($code === 'CfB' && ! $newOwnerBusinesses) {
                $value = 0;
            }
            $amounts[$code] = $value;
        }

        return array_replace(array_fill_keys(self::CATEGORIES, 0.0), $amounts);
    }

    private function grantValue(GrantAssistance $grant, float $base): float
    {
        return match ($grant->calculation_type) {
            'FIXED' => (float) $grant->amount,
            'PERCENTAGE' => $base * (float) $grant->percentage / 100,
            'FIXED_AND_PERCENTAGE' => (float) $grant->amount + $base * (float) $grant->percentage / 100,
            default => 0,
        };
    }

    private function daMultiplier(GrantAssistance $grant, AffectedPerson $ep, $land): float
    {
        $records = $grant->rates->where('is_active', true);
        if ($records->isEmpty()) {
            return (float) $grant->multiplier;
        }
        $category = strtolower(trim((string) ($land->land_type_other ?: ($land->landType?->name ?? ''))));
        $match = $records->filter(fn ($r) => ($r->district_id === null || (int) $r->district_id === (int) $ep->district_id)
            && ($r->upazila_id === null || (int) $r->upazila_id === (int) $ep->upazila_id)
            && (! $r->land_category || strtolower(trim((string) $r->land_category)) === $category)
        )->sortByDesc(fn ($r) => (int) ($r->district_id !== null) + (int) ($r->upazila_id !== null) + (int) ! empty($r->land_category))->first();

        return (float) ($match?->multiplier ?? $grant->multiplier);
    }

    private function decimalArea($land): float
    {
        return (float) $land->land_area * (self::DECIMAL_PER_UNIT[(int) $land->land_area_unit_id] ?? 1);
    }

    private function assessmentTotal($items, string $relation, string $field): float
    {
        return (float) $items->sum(fn ($item) => (float) ($item->{$relation}?->{$field} ?? 0));
    }

    private function cropTotal($lands): float
    {
        return (float) $lands->flatMap(fn ($land) => $land->crops)->sum(fn ($crop) => (float) ($crop->assessment?->assessed_loss ?? 0));
    }

    private function treeRateTotal(AffectedPerson $ep, $trees, $rates): float
    {
        return (float) $trees->sum(function ($tree) use ($ep, $rates) {
            $type = (string) ($tree->treeType?->name ?? '');
            if (preg_match('/different variety|variety/i', $type)) {
                $type = 'Different Variety';
            } elseif (preg_match('/various/i', $type)) {
                $type = 'Various';
            } elseif (preg_match('/total tree/i', $type)) {
                $type = 'Total Tree';
            } elseif (strcasecmp($type, 'bamboo bush') === 0) {
                $type = 'Bamboo bush';
            } elseif (preg_match('/^timber\s*(?:&|and)\s*fruit$/i', $type)) {
                $type = 'Timber & fruit';
            }
            $age = (string) ($tree->treeAgeCategory?->name ?? '');
            $size = preg_match('/big|large/i', $age) ? 'Large' : (preg_match('/medium/i', $age) ? 'Medium' : (preg_match('/small/i', $age) ? 'Small' : (preg_match('/sapling|plant/i', $age) ? 'Sapling' : '')));
            $rate = $rates->filter(fn ($r) => ($r->district_id === null || (int) $r->district_id === (int) $ep->district_id)
                && ($r->upazila_id === null || (int) $r->upazila_id === (int) $ep->upazila_id)
                && (string) $r->tree_type === $type && (string) $r->size === $size
            )->sortByDesc(fn ($r) => (int) ($r->district_id !== null) + (int) ($r->upazila_id !== null))->first();

            return (float) $tree->quantity * (float) ($rate?->rate ?? 0);
        });
    }
}
