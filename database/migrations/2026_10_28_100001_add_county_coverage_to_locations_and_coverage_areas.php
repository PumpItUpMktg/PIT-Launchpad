<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * County-based coverage: each Location carries its auto-resolved home county (5-digit
 * GEOID) and the owner's selected counties served. Coverage areas carry ACS population
 * for the Large/Medium/Small grouping. (The radius `coverage_radius` column is retired —
 * left in place, unused.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('home_county_geoid')->nullable()->after('coverage_radius');
            $table->json('county_geoids')->nullable()->after('home_county_geoid');
        });

        Schema::table('coverage_areas', function (Blueprint $table) {
            $table->unsignedInteger('population')->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['home_county_geoid', 'county_geoids']);
        });
        Schema::table('coverage_areas', function (Blueprint $table) {
            $table->dropColumn('population');
        });
    }
};
