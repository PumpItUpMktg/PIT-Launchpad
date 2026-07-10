<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Location model IS the market record (no separate market concept for location pages):
 * - served_towns — the GBP service-area town list: [{name, state, lat, lng, geocoded, place_id?}].
 *   Hard rule: a town belongs to exactly ONE location per site (validated at the form).
 * - market_notes — owner grounding, free text (years in the market, soil/water quirks, response
 *   claims). Feeds the drafter VERBATIM as trusted context.
 * - grounding_cache — structured local facts fetched at page-generation time ({facts, sources,
 *   fetched_at}); regeneration within 90 days reuses it.
 * - primary_category — the GBP category string (the future GBP API prefills these fields).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->json('served_towns')->nullable()->after('county_geoids');
            $table->text('market_notes')->nullable()->after('served_towns');
            $table->json('grounding_cache')->nullable()->after('market_notes');
            $table->string('primary_category')->nullable()->after('place_id');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['served_towns', 'market_notes', 'grounding_cache', 'primary_category']);
        });
    }
};
