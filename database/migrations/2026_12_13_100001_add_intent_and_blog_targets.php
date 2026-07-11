<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Longtail → blog target queue relay: the `intent` tag on spokes (classification-time) and §5
 * keywords (the queue's record), plus the per-silo blog target queue itself — the directed
 * assignment lane the news-post drafting consumes. One keyword, one home: the unique keyword_id
 * makes double-queuing structurally impossible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spokes', function (Blueprint $table): void {
            $table->string('intent')->nullable()->after('head_keyword');
        });

        // NOTE: keywords.intent already exists (§5 scoring uses the same
        // transactional/commercial/informational taxonomy, read as a string) — only spokes gain it.

        Schema::create('blog_targets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('silo_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('keyword_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued');
            // Deferred-FK style (like Content.location_id): the consuming article's Content ULID.
            $table->ulid('article_ref')->nullable()->index();
            $table->timestamp('queued_at');
            $table->timestamps();

            $table->index(['site_id', 'silo_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_targets');
        Schema::table('spokes', function (Blueprint $table): void {
            $table->dropColumn('intent');
        });
    }
};
