<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FEMA flood-zone composition per town, from the National Flood Hazard Layer.
 *
 * Global and keyed by Census GEOID for the same reasons as `census_housing`: the mapping describes a
 * place, so tenants covering the same township share the row, and a coverage rebuild deletes every
 * computed `coverage_areas` row, which would discard anything stored against one.
 *
 * `zones` holds what the NFHL returned for the town's own boundary — the zone code, whether FEMA counts
 * it a Special Flood Hazard Area, and how many mapped polygons carried it. The COUNT is provenance, not
 * magnitude: intersecting polygons are not clipped to the town, so no share of the town's area can be
 * claimed from it, and none is.
 *
 * `mapped` separates "FEMA maps no flood hazard here" from "FEMA has not mapped here" — the NFHL does not
 * cover every community, and those two say very different things on a page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('town_flood_zones', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('geo_id')->unique();
            $table->string('name');
            $table->string('state', 2)->nullable();
            $table->boolean('mapped')->default(false);     // the NFHL returned anything at all
            $table->boolean('has_sfha')->default(false);   // any zone with SFHA_TF = 'T'
            $table->json('zones')->nullable();             // [{zone, sfha, polygons}, …]
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('town_flood_zones');
    }
};
