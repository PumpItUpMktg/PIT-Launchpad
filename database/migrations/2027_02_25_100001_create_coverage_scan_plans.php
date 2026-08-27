<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The operator's per-GBP coverage scan schedule: for one Location, which keywords to scan its served towns
 * for, how often (cadence), and when it next runs. One plan per location. The daily
 * `launchpad:coverage-run-due` command dispatches the queued scans for plans whose `next_run_at` has passed,
 * then advances it by the cadence. Cost per run is derived (towns × keywords), not stored.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coverage_scan_plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->ulid('location_id')->index();            // deferred-FK (Location has global scopes); one plan per location
            $table->json('keyword_ids')->default('[]');      // the keywords this GBP scans (operator-selected)
            $table->string('cadence')->default('monthly');   // ScanCadence: monthly | weekly | off
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();   // due when <= now and enabled; null when Off
            $table->timestamps();

            $table->unique(['site_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coverage_scan_plans');
    }
};
