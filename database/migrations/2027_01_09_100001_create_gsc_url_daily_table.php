<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * gsc_url_daily — the URL-level daily GSC snapshot, retained indefinitely.
 *
 * One row per (site, date, url). `position` is Search Console's NATIVE
 * impression-weighted blended position for the URL (pulled with the
 * [date, page] dimension set, so it needs no client-side re-weighting and
 * is immune to the query-row thresholding that would bias a hand-rolled
 * average). This is the per-URL cohort series the lifecycle relay's refresh
 * signals read. Idempotent upserts key on `grain_hash` (sha256 of the grain
 * tuple) so a trailing-window re-pull absorbs GSC's ~3-day revisions without
 * double-counting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gsc_url_daily', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            // sha256(site_id|date|url) — the idempotency key for upserts.
            $table->char('grain_hash', 64);
            $table->date('date');
            $table->string('url', 2048);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('ctr', 7, 4)->default(0);
            // GSC's native blended position (nullable — a 0-impression row has none).
            $table->decimal('position', 6, 2)->nullable();
            $table->timestamps();

            $table->unique('grain_hash');
            $table->index(['site_id', 'date']);
            $table->index(['site_id', 'url']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_url_daily');
    }
};
