<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proximity coverage: a location may define its territory by DISTANCE (rings of miles from the shop)
 * instead of by county. An auto shop draws from 10–15 miles, not a county; the county mode's
 * "largest towns first" roll-out is wrong for it — the nearest towns should build first.
 *
 * `locations.coverage_mode` — `county` (default, unchanged) or `proximity` (the retained
 * `coverage_radius` column becomes the outer reach in miles).
 *
 * `coverage_areas.band` — the roll-out band a town belongs to, derived at write time like `size_tier`:
 * the size tier in county mode (major/large/…), a distance ring (`ring5`, `ring10`, …) in proximity mode.
 * The tier gate, the drip, and the panels read the band; `size_tier` stays the population grouping.
 * Existing rows are county-mode, so their band IS their size tier — backfilled here so no existing
 * site's locks or panels change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->string('coverage_mode', 12)->default('county')->after('coverage_radius');
        });

        Schema::table('coverage_areas', function (Blueprint $table): void {
            $table->string('band', 16)->nullable()->after('size_tier');
        });

        DB::table('coverage_areas')->whereNotNull('size_tier')->update(['band' => DB::raw('size_tier')]);
    }

    public function down(): void
    {
        Schema::table('coverage_areas', function (Blueprint $table): void {
            $table->dropColumn('band');
        });

        Schema::table('locations', function (Blueprint $table): void {
            $table->dropColumn('coverage_mode');
        });
    }
};
