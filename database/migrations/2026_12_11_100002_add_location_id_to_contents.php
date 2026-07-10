<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The location a LOCATION page belongs to — one rich landing page per Location row (the Location
 * model IS the market record). Indexed ULID, deferred-FK style like the other cross-cycle pins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->ulid('location_id')->nullable()->index()->after('market_id');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn('location_id');
        });
    }
};
