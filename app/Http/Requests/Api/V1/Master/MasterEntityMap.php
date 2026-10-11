<?php

namespace App\Http\Requests\Api\V1\Master;

use App\Models\BusinessCategory;
use App\Models\BusinessOwnershipType;
use App\Models\BusinessType;
use App\Models\CropSeason;
use App\Models\CropType;
use App\Models\District;
use App\Models\EmploymentType;
use App\Models\Gender;
use App\Models\InfrastructureType;
use App\Models\LandDamageType;
use App\Models\LandStatus;
use App\Models\LandType;
use App\Models\LandUseType;
use App\Models\LocationSource;
use App\Models\Occupation;
use App\Models\OwnershipType;
use App\Models\PaymentMethod;
use App\Models\Relationship;
use App\Models\Religion;
use App\Models\ResidentialCommercialType;
use App\Models\TenantType;
use App\Models\TreeAgeCategory;
use App\Models\TreeType;
use App\Models\Unit;
use App\Models\Upazila;
use App\Models\UsageType;
use App\Models\Village;

/**
 * Static whitelist of master entity configurations.
 *
 * Single source of truth shared by MasterController and FormRequests.
 */
final class MasterEntityMap
{
    /**
     * Whitelist of allowed entity slugs → model + PK + flags.
     *
     * @var array<string, array{model: class-string, table: string, pk: string, has_code: bool, parent_fk?: array{table: string, key: string, column: string}}>
     */
    public const ENTITIES = [
        'district' => ['model' => District::class, 'table' => 'district', 'pk' => 'district_id', 'has_code' => true],
        'upazila' => ['model' => Upazila::class, 'table' => 'upazila', 'pk' => 'upazila_id', 'has_code' => true, 'parent_fk' => ['table' => 'district', 'key' => 'district_id', 'column' => 'district_id']],
        'village' => ['model' => Village::class, 'table' => 'village', 'pk' => 'village_id', 'has_code' => false, 'parent_fk' => ['table' => 'upazila', 'key' => 'upazila_id', 'column' => 'upazila_id']],
        'religion' => ['model' => Religion::class, 'table' => 'religion', 'pk' => 'religion_id', 'has_code' => true],
        'gender' => ['model' => Gender::class, 'table' => 'gender', 'pk' => 'gender_id', 'has_code' => true],
        'occupation' => ['model' => Occupation::class, 'table' => 'occupation', 'pk' => 'occupation_id', 'has_code' => true],
        'relationship' => ['model' => Relationship::class, 'table' => 'relationship', 'pk' => 'relationship_id', 'has_code' => true],
        'land_status' => ['model' => LandStatus::class, 'table' => 'land_status', 'pk' => 'land_status_id', 'has_code' => true],
        'residential_commercial_type' => ['model' => ResidentialCommercialType::class, 'table' => 'residential_commercial_type', 'pk' => 'rc_type_id', 'has_code' => true],
        'tenant_type' => ['model' => TenantType::class, 'table' => 'tenant_type', 'pk' => 'tenant_type_id', 'has_code' => true],
        'land_type' => ['model' => LandType::class, 'table' => 'land_type', 'pk' => 'land_type_id', 'has_code' => true],
        'ownership_type' => ['model' => OwnershipType::class, 'table' => 'ownership_type', 'pk' => 'ownership_type_id', 'has_code' => true],
        'land_use_type' => ['model' => LandUseType::class, 'table' => 'land_use_type', 'pk' => 'land_use_type_id', 'has_code' => true],
        'land_damage_type' => ['model' => LandDamageType::class, 'table' => 'land_damage_type', 'pk' => 'land_damage_type_id', 'has_code' => true],
        'crop_type' => ['model' => CropType::class, 'table' => 'crop_type', 'pk' => 'crop_type_id', 'has_code' => true],
        'crop_season' => ['model' => CropSeason::class, 'table' => 'crop_season', 'pk' => 'crop_season_id', 'has_code' => true],
        'tree_type' => ['model' => TreeType::class, 'table' => 'tree_type', 'pk' => 'tree_type_id', 'has_code' => true],
        'tree_age_category' => ['model' => TreeAgeCategory::class, 'table' => 'tree_age_category', 'pk' => 'tree_age_category_id', 'has_code' => true],
        'infrastructure_type' => ['model' => InfrastructureType::class, 'table' => 'infrastructure_type', 'pk' => 'infrastructure_type_id', 'has_code' => true],
        'usage_type' => ['model' => UsageType::class, 'table' => 'usage_type', 'pk' => 'usage_type_id', 'has_code' => true],
        'business_type' => ['model' => BusinessType::class, 'table' => 'business_type', 'pk' => 'business_type_id', 'has_code' => true],
        'business_category' => ['model' => BusinessCategory::class, 'table' => 'business_category', 'pk' => 'business_category_id', 'has_code' => true],
        'business_ownership_type' => ['model' => BusinessOwnershipType::class, 'table' => 'business_ownership_type', 'pk' => 'business_ownership_type_id', 'has_code' => true],
        'employment_type' => ['model' => EmploymentType::class, 'table' => 'employment_type', 'pk' => 'employment_type_id', 'has_code' => true],
        'unit' => ['model' => Unit::class, 'table' => 'unit', 'pk' => 'unit_id', 'has_code' => true],
        'payment_method' => ['model' => PaymentMethod::class, 'table' => 'payment_method', 'pk' => 'payment_method_id', 'has_code' => true],
        'location_source' => ['model' => LocationSource::class, 'table' => 'location_source', 'pk' => 'location_source_id', 'has_code' => true],
    ];
}
