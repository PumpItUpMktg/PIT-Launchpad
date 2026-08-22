<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per provider×range sync attempt (§ Client Dashboard v1): operator visibility, backfill
 * resumability, and the "data through {date}" value the client UI reads from the latest successful run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_sync_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->date('range_start')->nullable();
            $table->date('range_end')->nullable();
            $table->string('status')->default('running'); // running | success | partial | failed
            $table->unsignedInteger('rows_written')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'provider', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_sync_runs');
    }
};
