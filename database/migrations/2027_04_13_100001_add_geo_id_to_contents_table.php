<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            // The census GEOID a TOWN page belongs to (place-7 / cousub-10) — the SAME identity a
            // CoverageArea carries (`coverage_areas.geo_id`) and a captured job resolves to
            // (`JobCity.place_geoid`). Until now a town page had no geo key: consumers matched it to its
            // coverage area BY NAME (title → App\Support\TownName::key), which collapses "Washington" /
            // "Springfield" / "Montgomery" across counties — the name-match class behind Trooper, Spring
            // City, served_towns and resolveMarket. Anchoring the page to a GEOID turns the whole
            // job→town-page chain into a pure GEOID join with no name-match at any hop.
            //
            // Nullable — only town pages get one, and only where the name resolves to EXACTLY ONE coverage
            // area; ambiguous/zero-match pages stay null and are surfaced by launchpad:anchor-town-pages
            // rather than guessed. NOT backfilled here: the backfill derives a clean key from a dirty match,
            // so it is report-first (the command's default) and writes only under --execute.
            $table->string('geo_id')->nullable()->after('parent_location_id');
            $table->index(['site_id', 'geo_id']);
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->dropIndex(['site_id', 'geo_id']);
            $table->dropColumn('geo_id');
        });
    }
};
