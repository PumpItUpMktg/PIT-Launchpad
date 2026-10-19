<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The backlink index, per site (§ Links). `site_backlinks` holds ONE row per referring domain — the
 * strongest live link from it, as the index reports it — replaced whole on every sync. It is the second
 * evidence source the citation workspace reads (does this directory actually link here, from which
 * listing, to which page), the dead-URL ledger, and the base for the competitor link intersect.
 * `backlink_sync_runs` is the per-site sync ledger (totals, rows stored, cost, errors).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_backlinks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('domain_from')->index();           // the referring domain, no www
            $table->string('url_from', 2048);                 // the linking page
            $table->string('url_to', 2048);                   // the page it points at, as linked
            $table->string('target_path', 512)->index();      // url_to's path, normalized ("/" for home)
            $table->unsignedSmallInteger('domain_from_rank')->nullable(); // 0-100
            $table->unsignedSmallInteger('page_from_rank')->nullable();   // 0-100
            $table->unsignedSmallInteger('spam_score')->nullable();       // 0-100
            $table->boolean('dofollow')->default(true);
            $table->boolean('is_broken')->default(false);     // the index's verdict on url_to, may be stale
            $table->text('anchor')->nullable();
            $table->date('first_seen')->nullable();
            $table->date('last_seen')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['site_id', 'domain_from']);
            $table->index(['site_id', 'target_path']);
        });

        Schema::create('backlink_sync_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('target');                         // the host synced
            $table->string('status')->default('complete');    // complete | failed
            $table->unsignedInteger('backlinks')->default(0);
            $table->unsignedInteger('referring_domains')->default(0);
            $table->unsignedInteger('broken_backlinks')->default(0);
            $table->unsignedSmallInteger('rank')->nullable();  // 0-100
            $table->unsignedInteger('rows_stored')->default(0);
            $table->unsignedSmallInteger('pages_fetched')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->index(['site_id', 'fetched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backlink_sync_runs');
        Schema::dropIfExists('site_backlinks');
    }
};
