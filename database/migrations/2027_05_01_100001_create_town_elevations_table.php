<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-town ground elevation, from the USGS National Map.
 *
 * Global and keyed by Census GEOID, like `census_housing` and `town_flood_zones`: the ground under a
 * town is not tenant data, and a coverage rebuild deletes every computed `coverage_areas` row.
 *
 * Feet, because that is the unit the copy uses; the source serves either and converting once at the
 * boundary beats converting at every read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('town_elevations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('geo_id')->unique();
            $table->string('name');
            $table->string('state', 2)->nullable();
            $table->decimal('elevation_ft', 8, 1)->nullable();   // null = asked, no value (offshore / no coverage)
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('town_elevations');
    }
};
