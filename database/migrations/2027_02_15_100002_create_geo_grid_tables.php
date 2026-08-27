<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geo Grid — PR 3 persistence. One `geo_grid_scans` header per (location × keyword × run) with the exact
 * geometry it was scanned at (center, grid_size, spacing, zoom, depth) so a result is reproducible and
 * comparable to a Local Falcon scan, plus the derived aggregates (§5 metrics, filled by PR 4). The raw
 * `geo_grid_points` are the source of truth — every aggregate is recomputable from them without rescanning.
 * Rank is nullable: null = the business did not appear within depth_cap at that point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_grid_scans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->ulid('location_id')->index();     // deferred-FK style (Location has global scopes)
            $table->ulid('keyword_id')->index();
            $table->string('provider')->default('dataforseo');
            $table->string('provider_scan_id')->nullable();   // batch/task marker for traceability

            // The exact geometry — a result is only comparable to Local Falcon at matching geometry.
            $table->unsignedSmallInteger('grid_size');
            $table->decimal('spacing_miles', 4, 2);
            $table->decimal('center_lat', 10, 7);
            $table->decimal('center_lng', 10, 7);
            $table->unsignedSmallInteger('zoom');
            $table->unsignedSmallInteger('depth_cap');

            // Derived aggregates (PR 4) — nullable until computed; recomputable from the points.
            $table->decimal('arp', 6, 2)->nullable();          // mean rank where found
            $table->decimal('atrp', 6, 2)->nullable();         // mean rank, non-found = depth_cap + 1
            $table->decimal('solv', 5, 2)->nullable();         // % of points ranked 1–3
            $table->decimal('found_rate', 5, 2)->nullable();   // % of points found at all

            $table->string('status')->default('pending');
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'location_id', 'keyword_id', 'scanned_at']);
        });

        Schema::create('geo_grid_points', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();   // tenant isolation on every model
            $table->foreignUlid('scan_id')->constrained('geo_grid_scans')->cascadeOnDelete();
            $table->unsignedSmallInteger('row');
            $table->unsignedSmallInteger('col');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->unsignedSmallInteger('rank')->nullable();  // null = not found within depth_cap
            $table->json('competitors')->nullable();           // top 3 at this point: {name, place_id, rank}
            $table->string('provider_task_id')->nullable();    // the DataForSEO task backing this cell
            $table->timestamps();

            $table->unique(['scan_id', 'row', 'col']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_grid_points');
        Schema::dropIfExists('geo_grid_scans');
    }
};
