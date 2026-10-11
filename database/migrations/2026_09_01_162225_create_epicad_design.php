<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * epicad_design — user-authored EPICAD card templates.
 *
 * A design is a reusable card layout for one project. It is stored as a
 * JSON layout (the visual builder) and/or an uploaded HTML / DOCX template.
 * When active, the EPICAD tab renders cards using this design.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('epicad_design', function (Blueprint $table) {
            $table->bigIncrements('epicad_design_id');
            $table->unsignedInteger('project_id');
            $table->string('name', 150)->nullable();
            $table->string('orientation', 10)->default('portrait'); // portrait | landscape
            $table->string('page_size', 20)->default('A4');
            $table->longText('layout_json')->nullable();   // visual builder layout
            $table->longText('background_url')->nullable(); // uploaded bg image url
            $table->longText('css')->nullable();            // user CSS for html/fl provisions
            $table->longText('html_template')->nullable();  // uploaded HTML (a4+fl placeholder)
            $table->longText('docx_orig_filename')->nullable();
            $table->boolean('is_active')->default(false);
            $table->unsignedInteger('created_by')->nullable();
            $table->unsignedInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epicad_design');
    }
};
