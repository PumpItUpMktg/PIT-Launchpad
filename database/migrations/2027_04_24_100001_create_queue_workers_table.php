<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Queue worker heartbeats: one row per `queue:work` process, written by the worker itself from the queue
 * events (loop tick, job start, job finish, stop). The operator's Queue page and the stalled-worker banner
 * read it to say which lanes have a live worker, what each is doing, and how a worker that is gone
 * stopped — instead of inferring "down" from a backlog nobody has touched. GLOBAL — workers serve every
 * tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_workers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('worker_id')->unique();       // "{hostname}#{pid}" — the process identity
            $table->string('hostname');
            $table->unsignedInteger('pid');
            $table->string('connection')->nullable();     // the queue connection ("database")
            $table->string('queues')->nullable();         // the --queue list as given ("high,default")
            $table->timestamp('started_at');
            $table->timestamp('last_seen_at')->index();
            $table->string('current_job')->nullable();    // job class while one is in flight
            $table->string('current_queue')->nullable();
            $table->timestamp('current_job_started_at')->nullable();
            $table->unsignedInteger('jobs_processed')->default(0);
            $table->unsignedInteger('jobs_failed')->default(0);
            $table->unsignedInteger('memory_mb')->default(0);
            $table->timestamp('stopped_at')->nullable();
            $table->string('stop_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_workers');
    }
};
