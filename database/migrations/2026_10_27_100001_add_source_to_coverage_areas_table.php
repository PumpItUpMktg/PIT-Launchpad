<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual coverage additions: a coverage area is either radius-derived (auto, rebuilt on
 * every Compute) or manually added by the owner (directed — a targeted/gap town outside
 * the radius). `source` distinguishes them so a recompute rebuilds only the radius set
 * and the manual adds persist. A manual add is also the priority location-page signal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coverage_areas', function (Blueprint $table) {
            $table->string('source')->default('radius')->after('source_location_ids');
        });
    }

    public function down(): void
    {
        Schema::table('coverage_areas', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
