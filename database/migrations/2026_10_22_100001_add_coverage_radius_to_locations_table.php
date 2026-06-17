<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Locations layer treats each `Location` as a coverage BASE location: its geocoded
 * point (already captured by the Places/GBP import) plus a service-radius selection.
 * `coverage_radius` is one of the preset miles {10, 15, 25} (tuned for dense NE markets);
 * null = not yet configured for coverage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->unsignedSmallInteger('coverage_radius')->nullable()->after('lng');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('coverage_radius');
        });
    }
};
