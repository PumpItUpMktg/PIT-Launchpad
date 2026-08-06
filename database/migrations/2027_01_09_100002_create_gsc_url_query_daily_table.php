<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * gsc_url_query_daily — the URL×query daily GSC snapshot at full grain.
 *
 * One row per (site, date, url, query, country, device). This grain powers
 * distinct-query-count-over-time, banded top-3/10/20 counts, and query
 * discovery. It grows fast, so it is kept at full grain only for a recent
 * window (`launchpad.gsc.query_grain_retention_days`, default 180d), after
 * which it is rolled up into gsc_url_query_monthly and pruned. Idempotent
 * upserts key on `grain_hash` (sha256 of the grain tuple).
 *
 * `position` here is per-(url,query) — the honest rank for that exact query,
 * NOT a blend. URL-level blended position lives in gsc_url_daily.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsc_url_query_daily', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            // sha256(site_id|date|url|query|country|device) — the upsert key.
            $table->char('grain_hash', 64);
            $table->date('date');
            $table->string('url', 2048);
            $table->string('query', 512);
            $table->string('country', 8);   // GSC 3-letter lowercase, e.g. "usa"
            $table->string('device', 16);   // MOBILE | DESKTOP | TABLET
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('ctr', 7, 4)->default(0);
            $table->decimal('position', 6, 2)->nullable();
            $table->timestamps();

            $table->unique('grain_hash');
            $table->index(['site_id', 'date']);
            $table->index(['site_id', 'query']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_url_query_daily');
    }
};
