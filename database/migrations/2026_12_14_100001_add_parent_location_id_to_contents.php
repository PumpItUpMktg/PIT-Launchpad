<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live-pages relay: the town-page → physical-Location assignment. DELIBERATELY a separate column
 * from `location_id` — that one is the composeLocation pin (the page that IS a location's landing
 * page); this one groups a coverage-era TOWN page under the location that serves its town (derived
 * from served_towns, where the cannibalization guard already makes the mapping unique). Deferred-FK
 * style like the other cross-links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->ulid('parent_location_id')->nullable()->index()->after('location_id');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->dropColumn('parent_location_id');
        });
    }
};
