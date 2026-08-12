<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Job Capture §1 — the canonical city/place registry. GLOBAL (no site_id), same rationale as job_counties:
 * FIPS identity is nationally unique and shared across tenants. `place_geoid` is the Census place (7-digit)
 * or county-subdivision (10-digit) GEOID — the identity that normalizes "Bedminster Twp" vs "Bedminster" to
 * one row. Each city soft-links to its county (job_county_id, nullable — resolved by the §4 geography
 * pipeline). Population + size_tier mirror the fields Sandhog Works already computes for served-town
 * ordering; slug is state-suffixed (bedminster-nj).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_cities', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('place_geoid')->unique();         // place (7) or county-subdivision (10) GEOID — identity
            $table->foreignUlid('job_county_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('state', 2)->nullable();
            $table->string('type')->default('place');        // MunicipalityType: place | county_subdivision
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->unsignedInteger('population')->nullable();
            $table->string('size_tier')->nullable();         // SizeTier: major|large|medium|small
            $table->string('slug')->unique();                // state-suffixed: bedminster-nj
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_cities');
    }
};
