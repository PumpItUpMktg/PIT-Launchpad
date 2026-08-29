<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Citation Management — the per-(location × directory) status: current state of one listing. The scan writes
 * one row per (location, directory), matched against the global catalog. Location-scoped (site_id +
 * location_id).
 *
 * The multi-location safety fields are the point: `attributed_location_id` records which sibling a found
 * result ACTUALLY belongs to (attribution-before-judging), and `attribution_confidence` gates ambiguous
 * results to operator review instead of a guess. A result attributed to a sibling is `sibling_listing` /
 * `covered_by_sibling` and can NEVER become a fix/duplicate/work-order item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('citation_statuses', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('directory_id')->constrained()->cascadeOnDelete();

            $table->string('state')->default('not_listed');   // CitationState

            // As-scraped (the listing we found), before normalization.
            $table->string('found_url')->nullable();
            $table->string('found_name')->nullable();
            $table->string('found_address')->nullable();
            $table->string('found_phone')->nullable();

            // Attribution-before-judging (multi-location Fix 1).
            $table->foreignUlid('attributed_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->unsignedTinyInteger('attribution_confidence')->nullable();   // 0-100
            $table->jsonb('mismatch_fields')->nullable();      // which fields differ + how

            $table->string('source')->default('unknown');      // CitationSource — traceability
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamps();

            $table->unique(['location_id', 'directory_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('citation_statuses');
    }
};
