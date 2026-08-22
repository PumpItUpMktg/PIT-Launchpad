<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The client-dashboard metric spine (§ Client Dashboard v1): provider-agnostic, dimension-agnostic,
 * trendable by construction. Every reportable number the portal shows is a row here — never read live from
 * a provider. All writes are idempotent upserts on the unique key, so backfill and retry are trivially safe.
 *
 * `dimension_value` is NOT NULL (default '') on purpose: a nullable column can't act as an idempotency key
 * (NULLs compare distinct in a unique index), so a site-level row keys on '' rather than NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('provider');                 // gsc | dataforseo | internal
            $table->string('metric_key');               // impressions | clicks | keywords_top10 | pages_published …
            $table->string('dimension_type');           // site | page | page_type | query | keyword | device | location
            $table->string('dimension_value')->default(''); // URL, keyword, 'service'|'location'|'blog'|'core', … ('' = site-level)
            $table->string('period_grain');             // day | week | month
            $table->date('period_date');                // first day of the period
            $table->decimal('value_numeric', 20, 4)->nullable();
            $table->jsonb('value_json')->nullable();    // SERP features, top-N lists, distributions
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(
                ['site_id', 'provider', 'metric_key', 'dimension_type', 'dimension_value', 'period_grain', 'period_date'],
                'metric_snapshots_grain_unique',
            );
            $table->index(['site_id', 'metric_key', 'period_grain', 'period_date'], 'metric_snapshots_query_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_snapshots');
    }
};
