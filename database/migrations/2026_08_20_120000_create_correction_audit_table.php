<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * correction_audit — Field-level data-correction audit trail.
 *
 * Complements status_history (which tracks status transitions only).
 * This table tracks WHAT data changed, with before/after values.
 * Append-only: no UPDATE or DELETE allowed in application code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('correction_audit', function (Blueprint $table) {
            $table->id('correction_audit_id');
            $table->unsignedBigInteger('project_id')->index();
            $table->string('entity_type', 50)->index();       // EP, LAND, TREE, INFRASTRUCTURE, CROP, TENANT, BUSINESS, WORKER, EC, COMPENSATION, VOUCHER
            $table->unsignedBigInteger('entity_id')->index();  // PK of the affected row
            $table->string('ep_id', 30)->nullable()->index(); // Denormalized EP ID for fast filtering
            $table->string('field_name', 100);                // Column name that changed
            $table->text('old_value')->nullable();             // Previous value (NULL if new)
            $table->text('new_value')->nullable();             // New value (NULL if deleted)
            $table->boolean('financial_impact')->default(false); // Was this a financial field?
            $table->string('correction_reason', 500);         // Why corrected (required)
            $table->unsignedBigInteger('corrected_by')->nullable(); // FK → users (ON DELETE SET NULL)
            $table->timestamp('corrected_at');                 // When corrected
            $table->boolean('authorized')->default(false);     // Was this authorized by an approver?
            $table->unsignedBigInteger('authorized_by')->nullable(); // FK → users (ON DELETE SET NULL)
            $table->text('remarks')->nullable();

            // No unique constraint — multiple corrections to same field are expected
            $table->index(['entity_type', 'entity_id']);
            $table->index(['project_id', 'entity_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correction_audit');
    }
};
