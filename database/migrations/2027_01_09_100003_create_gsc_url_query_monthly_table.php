<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * gsc_url_query_monthly — the long-term rollup of gsc_url_query_daily.
 *
 * One row per (site, month, url, query, country, device). Written when daily
 * query-grain rows age past the retention window: impressions/clicks summed,
 * `position` recomputed as the IMPRESSION-WEIGHTED average across the month's
 * daily rows (Σ position·impressions / Σ impressions — never a flat mean),
 * `days_present` recording how many daily rows rolled in. Retained
 * indefinitely so the distinct-query and banded top-3/10/20 trends survive
 * long after the daily detail is pruned. Idempotent on `grain_hash`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsc_url_query_monthly', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            // sha256(site_id|month|url|query|country|device) — the upsert key.
            $table->char('grain_hash', 64);
            $table->date('month'); // first day of the month
            $table->string('url', 2048);
            $table->string('query', 512);
            $table->string('country', 8);
            $table->string('device', 16);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            // Impression-weighted monthly position.
            $table->decimal('position', 6, 2)->nullable();
            $table->unsignedSmallInteger('days_present')->default(0);
            $table->timestamps();

            $table->unique('grain_hash');
            $table->index(['site_id', 'month']);
            $table->index(['site_id', 'query']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_url_query_monthly');
    }
};
