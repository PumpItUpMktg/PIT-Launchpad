<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Citation Management — the append-only event ledger (§ Citations, PR4). One row per meaningful transition of a
 * (location × directory) citation: discovered, fixed, regressed, lost, stalled. `from_state`/`to_state` capture
 * the CitationState change; `citation_scan_run_id` ties the event to the run that detected it. History is never
 * updated or deleted — it is the audit trail behind the diff buckets, regression alerts, and refresh-ROI view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('citation_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('directory_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('citation_scan_run_id')->nullable()->constrained()->nullOnDelete();

            $table->string('event_type');                 // CitationEventType
            $table->string('from_state')->nullable();      // CitationState
            $table->string('to_state')->nullable();        // CitationState
            $table->timestamp('occurred_at');
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'location_id', 'directory_id']);
            $table->index(['site_id', 'event_type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('citation_events');
    }
};
