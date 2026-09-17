<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-town housing stock from the Census ACS — the grounding a town page has never had.
 *
 * GLOBAL, keyed by Census GEOID, not per tenant: the numbers describe a place, not a customer, so two
 * clients covering the same township share the row and the request that fetched it. It is also the only
 * durable key available — a coverage rebuild deletes and re-inserts every computed `coverage_areas` row,
 * which would throw away tenant-stored housing data on every territory change.
 *
 * Raw counts are stored, never the derived shares: a share is recomputed from the counts whenever it is
 * read, so a denominator of zero reads as "unknown" instead of a fabricated 0%.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('census_housing', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('geo_id')->unique();          // 10-digit county subdivision or 7-digit place
            $table->string('name');
            $table->string('state', 2)->nullable();
            $table->string('county_geoid', 5)->nullable()->index();   // null for a place GEOID
            $table->string('acs_year', 4);

            $table->unsignedSmallInteger('median_year_built')->nullable();   // B25035_001E
            $table->unsignedInteger('occupied_units')->nullable();           // B25003_001E
            $table->unsignedInteger('owner_occupied_units')->nullable();     // B25003_002E
            $table->unsignedInteger('total_units')->nullable();              // B25024_001E
            $table->unsignedInteger('single_family_units')->nullable();      // B25024_002E + _003E
            $table->unsignedInteger('pre_1960_units')->nullable();           // B25034_009E + _010E + _011E

            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('census_housing');
    }
};
