<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geo Grid — PR 1 foundation. Two columns; the GBP place_id the grid centers on and matches ranks by
 * already lives on `locations.place_id`.
 *
 *  - `keywords.is_grid_keyword` — opt a keyword into geo-grid scanning (a scan is 49 DataForSEO requests
 *    per keyword per location, so this is deliberately explicit, not every tracked keyword).
 *  - `locations.grid_spacing_miles` — per-location point spacing (miles). Nullable → the default (1.5 for
 *    the Local Falcon parity test) applies. Stored per location so the box size is tunable later without a
 *    migration (a 9-mile box is dense-storefront sizing; a broad service area will want it wider).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('keywords', function (Blueprint $table): void {
            $table->boolean('is_grid_keyword')->default(false)->index();
        });

        Schema::table('locations', function (Blueprint $table): void {
            $table->decimal('grid_spacing_miles', 4, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('keywords', function (Blueprint $table): void {
            $table->dropColumn('is_grid_keyword');
        });

        Schema::table('locations', function (Blueprint $table): void {
            $table->dropColumn('grid_spacing_miles');
        });
    }
};
