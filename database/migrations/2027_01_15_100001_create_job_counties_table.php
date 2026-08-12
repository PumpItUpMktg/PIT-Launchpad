<?php

use App\Integrations\Census\County;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Job Capture §1 — the canonical county registry. GLOBAL (no site_id): a county's identity and population
 * are properties of the place, not a tenant, and county FIPS is nationally unique — so "Washington County"
 * resolves to one row shared across every tenant's jobs. Identity is the 5-digit STATE+COUNTY FIPS
 * ({@see County}); the slug is state-suffixed (washington-county-pa) so the display
 * label stays unambiguous. Population + size_tier are stored (free from the same Census/ACS call the
 * served-town ordering already uses); no v1 UI needs the tier on jobs, but it's captured here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_counties', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('county_geoid', 5)->unique();   // 5-digit STATE+COUNTY FIPS — the identity
            $table->string('state_fips', 2);
            $table->string('name');
            $table->string('state', 2)->nullable();          // 2-letter abbr for the state-aware display label
            $table->unsignedInteger('population')->nullable();
            $table->string('size_tier')->nullable();         // SizeTier: major|large|medium|small
            $table->string('slug')->unique();                // state-suffixed: washington-county-pa
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_counties');
    }
};
