<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Town Rank: a point records the Census GEOID of the town it measured, alongside the coverage-area row id.
 * The row id is a surrogate that a coverage rebuild throws away (CoverageWriter deletes every computed
 * CoverageArea and inserts fresh ULIDs), which orphaned every stored scan point and greyed the boards. The
 * GEOID is the town's durable identity — unique per site — so a rebuilt coverage set still finds its ranks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('town_rank_points', function (Blueprint $table): void {
            $table->string('geo_id')->nullable()->after('coverage_area_id');
            $table->index(['site_id', 'geo_id']);
        });
    }

    public function down(): void
    {
        Schema::table('town_rank_points', function (Blueprint $table): void {
            $table->dropIndex(['site_id', 'geo_id']);
            $table->dropColumn('geo_id');
        });
    }
};
