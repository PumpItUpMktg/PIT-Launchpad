<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            // A refresh re-drafts an existing page in place rather than creating
            // a new row. No DB FK (additive ALTER; matches the §1 deferred-FK
            // pattern) — the self-relation is enforced at the model level.
            $table->ulid('refresh_of_content_id')->nullable()->index();

            // Lane/trigger provenance for the review queue (§6c) and analytics.
            $table->string('draft_trigger')->nullable();
            $table->string('draft_lane')->nullable();

            // The post-draft verification pass result (claim tracing + source
            // attribution). Stored structurally so the review queue can surface
            // unsupported claims without re-running the pass.
            $table->json('verification')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropColumn(['refresh_of_content_id', 'draft_trigger', 'draft_lane', 'verification']);
        });
    }
};
