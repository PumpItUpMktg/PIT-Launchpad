<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The coverage-mode twin of the town-rank points\' GEOID: a Maps (map-pack) point records the Census GEOID
 * of the town it measured, so the Service Areas GBP map and the board\'s map-pack column still find their
 * town after a coverage rebuild has replaced every CoverageArea row id. Null in grid mode — a lattice cell
 * is not a town.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geo_grid_points', function (Blueprint $table): void {
            $table->string('geo_id')->nullable()->after('coverage_area_id');
            $table->index(['site_id', 'geo_id']);
        });
    }

    public function down(): void
    {
        Schema::table('geo_grid_points', function (Blueprint $table): void {
            $table->dropIndex(['site_id', 'geo_id']);
            $table->dropColumn('geo_id');
        });
    }
};
