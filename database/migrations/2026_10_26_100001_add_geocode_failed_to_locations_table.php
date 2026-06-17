<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Locations consolidated add-flow: geocoding runs in the background, so a base needs a
 * tri-state — has a point (located), or failed (surface a manual override). `lat`/`lng`
 * already carry "located"; this flags the failure so the override appears only then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->boolean('geocode_failed')->default(false)->after('coverage_radius');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('geocode_failed');
        });
    }
};
