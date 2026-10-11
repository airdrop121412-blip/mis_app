<?php

namespace App\Support;

/**
 * Canonical compensation categories — the 18 official DWASA categories
 * from the reference EC/Voucher documents (see docs/CATEGORY-RECONCILIATION-AUDIT.md).
 *
 * The old coarse codes (LAND, CROP, ...) remain accepted for backward
 * compatibility; new records should use these 18 reference codes.
 */
final class CompensationCategories
{
    public const CFL = 'CfL';                 // Compensation for Land

    public const CFS = 'CfS';                 // Compensation for Structure

    public const CFT = 'CfT';                 // Compensation for Trees

    public const CFSC = 'CfSC';               // Compensation for Standing Crops

    public const CFFS = 'CfFS';               // Compensation for Fish Stock

    public const RV_STRUCTURE = 'RV_STRUCTURE'; // RV for Structure (Squatters)

    public const LDC = 'LDC';                 // Land Development Cost

    public const DA = 'DA';                   // Dislocation Allowance

    public const SRC = 'SRC';                 // Stamp duty and Registration Cost

    public const HTG = 'HTG';                 // House Transfer Grant

    public const HCG = 'HCG';                 // House Construction Grant

    public const CFF = 'CfF';                 // Compensation for Fruits

    public const CAVHH = 'CAVHH';             // Cash Assistance for Vulnerable HH

    public const AVFHH = 'AVFHH';             // Allowance for Vulnerable and Female HH

    public const CFB = 'CfB';                 // Compensation for Business Loss

    public const MTG = 'MTG';                 // Material Transfer Grant

    public const SGFT = 'SGfT';               // Shifting Grant for Tenants

    public const GFWL = 'GfWL';               // Grant for Wage Loss

    /** The 18 official categories in document order. */
    public const ALL = [
        self::CFL,
        self::CFS,
        self::CFT,
        self::CFSC,
        self::CFFS,
        self::RV_STRUCTURE,
        self::LDC,
        self::DA,
        self::SRC,
        self::HTG,
        self::HCG,
        self::CFF,
        self::CAVHH,
        self::AVFHH,
        self::CFB,
        self::MTG,
        self::SGFT,
        self::GFWL,
    ];

    /** Legacy coarse codes still accepted for backward compatibility. */
    public const LEGACY = [
        'LAND',
        'CROP',
        'INFRASTRUCTURE',
        'TREE',
        'BUSINESS',
        'WORKER',
        'ALLOWANCE',
        'TENANT_GRANT',
        'BUSINESS_GRANT',
        'DISPLACEMENT',
        'REHABILITATION',
        'OTHER',
    ];

    /** All accepted category codes (18 canonical + legacy). */
    public const ACCEPTED = [...self::ALL, ...self::LEGACY];

    /** Comma-separated list for Laravel `in:` validation rules. */
    public static function ruleList(): string
    {
        return implode(',', self::ACCEPTED);
    }
}
