<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A spoke in a SiloBlueprint. The silo grouping (SiloBlueprint → silos → spokes) is
 * represented on the spoke via `silo` (the parent silo/pillar name) + `is_pillar`.
 * Phase 2 fills the tag/keyword/granularity; Phase 3 fills `volume`; Phase 4's prune
 * sets `status` + `page_type`. NOT populated in PR #1 — this is the contract shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spokes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('silo_blueprint_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            // The parent silo/pillar grouping name (the spec's "silos" layer); is_pillar marks the silo's pillar page.
            $table->string('silo')->nullable();
            $table->boolean('is_pillar')->default(false);
            $table->string('name');
            $table->string('page_type')->default('service');
            $table->string('tag')->default('core');
            $table->string('head_keyword')->nullable();
            $table->unsignedInteger('volume')->nullable();
            $table->string('status')->default('offered');
            $table->text('connection_note')->nullable();
            $table->string('granularity')->default('own_page');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spokes');
    }
};
