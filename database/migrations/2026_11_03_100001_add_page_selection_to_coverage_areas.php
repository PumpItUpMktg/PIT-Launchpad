<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Page-selection layer on coverage: each covered town carries a 4-tier `size_tier` (derived
 * from ACS population at write time via the tenant's thresholds; null = ungrouped) and a
 * `page_selected` flag — the drip pool of towns the owner wants location pages for. Manual
 * (priority-page) rows default selected. The per-site `coverage_thresholds` JSON overrides
 * the platform tier defaults (re-tiering is a cheap pass over existing rows, no ACS calls).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coverage_areas', function (Blueprint $table) {
            $table->string('size_tier')->nullable()->after('population'); // major|large|medium|small; null = ungrouped
            $table->boolean('page_selected')->default(false)->after('size_tier');
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->json('coverage_thresholds')->nullable()->after('budget_ceiling');
        });
    }

    public function down(): void
    {
        Schema::table('coverage_areas', function (Blueprint $table) {
            $table->dropColumn(['size_tier', 'page_selected']);
        });
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('coverage_thresholds');
        });
    }
};
