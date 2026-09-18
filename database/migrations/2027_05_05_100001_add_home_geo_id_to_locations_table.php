<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The municipality a GBP location physically sits in, as a census GEOID.
 *
 * `home_county_geoid` has always recorded the COUNTY the geocoded point falls in; nothing recorded the
 * town. That gap is why a location's own town kept appearing in its "no page yet" build queue: the
 * queue counts a town as covered when a page carries its GEOID, and a hub page is pinned through
 * `location_id` with a null `geo_id`, so nothing tied the hub to the municipality it stands in.
 *
 * Resolved from the same coordinates and the same gazetteer call as the county, MCD-first — which
 * matters here, because the office sits in exactly one of Doylestown borough and Doylestown township
 * and a name match cannot say which.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->string('home_geo_id', 12)->nullable()->after('home_county_geoid')->index();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropColumn('home_geo_id');
        });
    }
};
