<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The confirmed silo blueprint — the directed-coverage spine produced by the owner
 * interview arc. Scaffolded here (PR #1 of the arc); Phase 2 expansion fills it and
 * Phase 4's prune confirms it. Records the SiloSeed snapshot it was built from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('silo_blueprints', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('trade')->nullable();
            // The SiloSeed snapshot that produced this blueprint (trade, anchors, markets, exclusions, gbp_signals).
            $table->json('seed')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('silo_blueprints');
    }
};
