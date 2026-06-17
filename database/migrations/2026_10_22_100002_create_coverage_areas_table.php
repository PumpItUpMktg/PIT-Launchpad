<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The authoritative service-area coverage set: the municipalities (incorporated places
 * AND county subdivisions / MCDs — critical for NJ/PA) a tenant serves, derived by
 * unioning the Census enumeration within each base Location's radius. One row per
 * (site, GEOID); `source_location_ids` records which base location(s) reached it and
 * `distance_miles` the nearest. Drives areaServed schema, service-area content, and
 * Phase-3 service-area-localized keyword volume. NOT the location pages (a selected
 * subset = Markets, a later layer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coverage_areas', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('geo_id');                 // Census GEOID (place or county subdivision)
            $table->string('name');
            $table->string('type')->default('place'); // MunicipalityType: place | county_subdivision
            $table->string('state', 2)->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->decimal('distance_miles', 6, 2)->nullable(); // to the nearest base location
            $table->json('source_location_ids')->nullable();     // base Locations that reach it
            $table->timestamps();

            $table->unique(['site_id', 'geo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coverage_areas');
    }
};
