<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tiered-rollout "link plan on unlock" (§ link-plan relay). When a town tier unlocks and its pages are
 * built, a plan of proposed inbound links is generated from the five sources; the operator approves items,
 * then the committer writes the anchors (re-publishing each source) and submits IndexNow. Persisted because
 * the approval gate is asynchronous — nothing is written until an operator says so.
 *
 * A `link_plan_item` is one proposed edge: a `source_content` links to a `target_content` (a newly-built
 * town page) via `source_type` (which of the five sources proposed it) and an optional `anchor_term` (the
 * word to wrap; null → an appended "Related:" link, or a whole-page republish for the Areas source).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('link_plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->ulid('market_location_id')->nullable()->index(); // the Location whose tier unlocked
            $table->string('tier')->nullable();                      // the size tier (null = ungrouped)
            $table->string('status')->default('proposed');           // LinkPlanStatus
            $table->timestamps();

            $table->index(['site_id', 'status']);
        });

        Schema::create('link_plan_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('link_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->ulid('source_content_id')->nullable()->index(); // the page/post that gains the link
            $table->ulid('target_content_id')->index();             // the town page being linked to
            $table->string('source_type');                          // LinkSourceType
            $table->string('anchor_term')->nullable();              // the word to wrap; null → appended/whole-page
            $table->string('status')->default('proposed');          // LinkPlanItemStatus
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index(['link_plan_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('link_plan_items');
        Schema::dropIfExists('link_plans');
    }
};
