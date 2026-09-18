<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Summer humidity normals per NOAA weather station — the variable a dehumidification or mold-testing
 * page actually turns on.
 *
 * Keyed by STATION, not by town, because that is the honest granularity: dew point is regional, and the
 * 1991–2020 normals are published per station. Storing a value per town would put the identical sentence
 * on ninety pages and imply a precision the measurement does not have.
 *
 * Global, like the other reference tables: a station's normals describe a place, not a customer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('climate_stations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('station_id')->unique();     // NOAA GHCN id, e.g. USW00014737
            $table->string('name');
            $table->string('state', 2)->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->decimal('summer_dew_point_f', 5, 2)->nullable();   // July + August mean, °F
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('climate_stations');
    }
};
