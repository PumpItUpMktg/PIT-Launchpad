<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The GEO check activity log — one row per (prompt × engine) step a run takes, so the operator can see
     * exactly what the engine is doing in the background: what got measured (and whether we were cited /
     * who else was), what was skipped as still-fresh, and what was deferred when the budget ran out. Grouped
     * by `run_id` for per-run history. Append-only; pruned on a retention window.
     */
    public function up(): void
    {
        Schema::create('geo_check_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('site_id');
            $table->ulid('run_id');                       // groups one audit run's events
            $table->ulid('geo_prompt_id')->nullable();    // deferred-FK style (indexed, no constraint)
            $table->string('engine', 32)->nullable();
            $table->string('action', 24);                 // measured | skipped_fresh | deferred | error
            $table->boolean('cited')->nullable();         // only on `measured`
            $table->json('competitors')->nullable();      // only on `measured`
            $table->string('town')->nullable();           // the prompt's town, denormalized for display
            $table->timestamps();

            $table->index(['site_id', 'created_at']);
            $table->index(['site_id', 'run_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_check_events');
    }
};
