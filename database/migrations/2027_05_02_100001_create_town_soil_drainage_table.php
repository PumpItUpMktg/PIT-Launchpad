<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-town soil drainage, area-weighted, from the USDA soil survey (SSURGO).
 *
 * Global and keyed by Census GEOID, like its siblings: the ground under a town is not tenant data, and a
 * coverage rebuild deletes every computed `coverage_areas` row.
 *
 * `classes` holds every USDA drainage class found under the town with the SHARE of the town's mapped
 * ground it covers — a real area weight, computed by intersecting the soil polygons with the town's own
 * boundary, not a count of map units. `poorly_share` is the one number the copy actually uses: the
 * combined somewhat-poorly + poorly + very-poorly ground, which is what "clay" means to a homeowner with
 * a wet basement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('town_soil_drainage', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('geo_id')->unique();
            $table->string('name');
            $table->string('state', 2)->nullable();
            $table->boolean('surveyed')->default(false);        // SSURGO returned mapped ground at all
            $table->string('dominant')->nullable();             // the largest drainage class by area
            $table->decimal('poorly_share', 5, 4)->nullable();  // 0–1 of mapped ground draining poorly
            $table->json('classes')->nullable();                // [{class, share}, …] largest first
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('town_soil_drainage');
    }
};
