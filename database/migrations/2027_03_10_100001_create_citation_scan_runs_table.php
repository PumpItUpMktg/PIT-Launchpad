<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Citation Management — one row per citation scan of one location (§ Citations, PR4). It records when the scan
 * ran, the coverage snapshot at that moment (covered / needs-fix / not-listed counts + the score), and the
 * month-over-month diff buckets (new / fixed / regressed / lost) computed against the prior run. The run ledger
 * is what the operator's "what changed this month" view and the regression alerts read from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('citation_scan_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('location_id')->constrained()->cascadeOnDelete();

            $table->string('trigger')->default('scheduled');   // scheduled | manual | backfill
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            // Coverage snapshot at run time.
            $table->unsignedInteger('directories_evaluated')->default(0);
            $table->unsignedInteger('covered_count')->default(0);
            $table->unsignedInteger('needs_fix_count')->default(0);
            $table->unsignedInteger('not_listed_count')->default(0);
            $table->unsignedTinyInteger('score')->nullable();   // Local Presence Score at run time

            // Month-over-month diff buckets vs the prior run.
            $table->unsignedInteger('new_count')->default(0);
            $table->unsignedInteger('fixed_count')->default(0);
            $table->unsignedInteger('regressed_count')->default(0);
            $table->unsignedInteger('lost_count')->default(0);

            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'location_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('citation_scan_runs');
    }
};
