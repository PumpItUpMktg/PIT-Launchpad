<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repoint GEO's geography from the curated Market list onto the CoverageArea town set — the
     * authoritative, location-linked, size-tiered municipalities the platform actually publishes pages
     * for. A GEO prompt now targets one covered TOWN (its `coverage_area_id`) and carries that town's
     * `size_tier` (major|large|medium|small) so a budget-bounded run measures the biggest towns first.
     *
     * Hard cut: GEO logic stops reading `market_id` (the column stays in place, unused, so nothing is
     * destroyed and a rollback is clean); re-seeding rebuilds the prompt set on towns.
     */
    public function up(): void
    {
        Schema::table('geo_prompts', function (Blueprint $table) {
            // The covered town this prompt measures (deferred-FK style: indexed, no constraint, like the
            // other GEO dimension columns). Null for a service/brand-level prompt (HowTo / Reviews).
            $table->ulid('coverage_area_id')->nullable()->after('market_id');
            // Denormalized from the town so the audit can order major→small without a join per row.
            $table->string('size_tier', 16)->nullable()->after('coverage_area_id');

            $table->index(['site_id', 'coverage_area_id']);
        });
    }

    public function down(): void
    {
        Schema::table('geo_prompts', function (Blueprint $table) {
            $table->dropIndex(['site_id', 'coverage_area_id']);
            $table->dropColumn(['coverage_area_id', 'size_tier']);
        });
    }
};
