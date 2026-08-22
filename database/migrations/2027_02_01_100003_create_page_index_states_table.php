<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable per-URL index state (§ Client Dashboard v1). Today Google's index verdicts live only in the
 * cache (GoogleIndexInspector::Cache::put) — ephemeral and un-trendable. This is the durable home so the
 * portal can answer "how many pages has Google added" and derive the first-page-indexed milestone.
 *
 * Keyed on url_normalized so the URL Inspection sync is an idempotent upsert per page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_index_states', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->ulid('content_id')->nullable()->index(); // deferred FK — jobs/legacy URLs may have no Content row
            $table->string('url');
            $table->string('url_normalized');
            $table->string('coverage_state')->nullable();   // Google's coverageState text
            $table->string('index_verdict')->nullable();    // PASS | NEUTRAL | FAIL | null
            $table->string('robots_state')->nullable();
            $table->string('canonical_url')->nullable();
            $table->timestamp('last_crawled_at')->nullable();
            $table->timestamp('last_inspected_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'url_normalized']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_index_states');
    }
};
