<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site guided-setup progress (the 4-step flow + Grow). `current_step` is where the
 * operator is; the booleans are the per-step completion gates that unlock the next step —
 * this is where the pipeline's dependency order (services → territory → structure → approve)
 * becomes structural, so volume always grounds against a real territory. The build-config
 * columns are Step 4's "before we build" toggles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setup_states', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->unique()->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('current_step')->default(1);

            // Per-step completion gates (each unlocks the next step).
            $table->boolean('services_done')->default(false);
            $table->boolean('territory_done')->default(false);
            $table->boolean('structure_finalized')->default(false);
            $table->boolean('approved')->default(false);
            $table->boolean('launched')->default(false);

            // Step 4 build config ("before we build" — sensible defaults).
            $table->boolean('localize')->default(true);
            $table->unsignedSmallInteger('town_page_pace')->default(5);
            $table->boolean('fresh_content')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setup_states');
    }
};
