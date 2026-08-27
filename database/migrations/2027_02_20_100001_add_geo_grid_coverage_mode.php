<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geo Grid — coverage mode (town-centered scanning). Adds the ability to scan a location's actual served
 * TOWNS (each municipality's centroid) instead of an abstract square lattice, so a scan answers the
 * business-meaningful question "are we winning the map pack in the towns we target?" and the rank at each
 * point joins straight to that town's page / jobs / reviews via `coverage_area_id`.
 *
 * Purely additive (no column changes): a `mode` discriminator on the scan header, population-weighted
 * aggregates (visibility where the customers actually are), and the town identity on each point. A coverage
 * point reuses row=0/col=index for the existing (scan_id,row,col) unique key but is identified by
 * `coverage_area_id`. Grid-mode scans are unaffected (mode defaults to 'grid', new columns null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geo_grid_scans', function (Blueprint $table): void {
            $table->string('mode')->default('grid')->after('provider');   // grid | coverage
            $table->decimal('pop_found_rate', 5, 2)->nullable()->after('found_rate');  // % of POPULATION in found towns
            $table->decimal('pop_solv', 5, 2)->nullable()->after('pop_found_rate');    // % of POPULATION in top-3 towns
        });

        Schema::table('geo_grid_points', function (Blueprint $table): void {
            $table->ulid('coverage_area_id')->nullable()->after('col')->index();  // the town this point measures (coverage mode)
            $table->string('label')->nullable()->after('coverage_area_id');       // town name, for at-a-glance reads
        });
    }

    public function down(): void
    {
        Schema::table('geo_grid_points', function (Blueprint $table): void {
            $table->dropColumn(['coverage_area_id', 'label']);
        });
        Schema::table('geo_grid_scans', function (Blueprint $table): void {
            $table->dropColumn(['mode', 'pop_found_rate', 'pop_solv']);
        });
    }
};
