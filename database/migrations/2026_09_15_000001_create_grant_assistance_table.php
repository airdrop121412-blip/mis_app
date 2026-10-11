<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * grant_assistance — Admin → Grant and Assistance.
 *
 * One config row per resettlement grant / assistance item (LDC, DA, SRC,
 * HTG, HCG, CAVHH, AVFHH, MTG, SGfT, GfWL). The EC File engine reads these
 * to populate the Compensation column for those heads:
 *
 *   FIXED               → amount
 *   PERCENTAGE          → base × percentage/100   (base = PAVC or RV_STRUCTURE)
 *   FIXED_AND_PERCENTAGE→ amount + base × percentage/100
 *   MULTIPLIER          → total land (decimals) × multiplier   (DA)
 *
 * Schema follows the authoritative MIS conventions (BaseModel): an
 * `<entity>_id` surrogate PK and no Laravel timestamps.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grant_assistance', function (Blueprint $table) {
            $table->bigIncrements('grant_assistance_id');
            $table->string('grant_code', 30)->unique();
            $table->string('grant_name', 255);
            $table->string('calculation_type', 20);
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('percentage', 5, 2)->default(0);
            $table->string('percentage_base', 20)->nullable();
            $table->string('category', 255)->nullable();
            $table->decimal('multiplier', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);
        });

        $rows = [
            ['LDC', 'Land Development Cost', 'PERCENTAGE', 'PAVC'],
            ['DA', 'Dislocation Allowance', 'MULTIPLIER', null],
            ['SRC', 'Stamp duty and Registration Cost', 'FIXED', null],
            ['HTG', 'House Transfer Grant', 'FIXED_AND_PERCENTAGE', 'RV_STRUCTURE'],
            ['HCG', 'House Construction Grant', 'FIXED_AND_PERCENTAGE', 'RV_STRUCTURE'],
            ['CAVHH', 'Cash Assistance for Vulnerable HH', 'FIXED', null],
            ['AVFHH', 'Allowance for Vulnerable and Female HH', 'FIXED', null],
            ['MTG', 'Material Transfer Grant', 'FIXED', null],
            ['SGfT', 'Shifting Grant for Tenants', 'FIXED', null],
            ['GfWL', 'Grant for Wage Loss', 'FIXED', null],
        ];

        foreach ($rows as [$code, $name, $type, $base]) {
            DB::table('grant_assistance')->insert([
                'grant_code' => $code,
                'grant_name' => $name,
                'calculation_type' => $type,
                'amount' => 0,
                'percentage' => 0,
                'percentage_base' => $base,
                'category' => null,
                'multiplier' => 0,
                'is_active' => true,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('grant_assistance');
    }
};
