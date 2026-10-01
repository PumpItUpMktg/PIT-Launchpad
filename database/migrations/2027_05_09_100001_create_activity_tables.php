<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The activity log (§ Activity): `activity_events` holds the operator actions that leave no other trace
 * (a manual re-push, a rejection, a priority push from the map) — everything else the log shows is derived
 * from the records the work itself writes. `site_monthly_snapshots` freezes each closed month's headline
 * counts and metric movement so long-term progress never drifts when old data is pruned or re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->string('kind');                       // repush | content_rejected | priority_keyword | priority_push | …
            $table->timestamp('occurred_at');
            $table->string('subject')->nullable();        // what it acted on, for the sentence ("Warren, NJ", "28 pages")
            $table->string('summary', 500);               // the sentence the timeline shows
            $table->json('metrics')->nullable();          // counts that ride with it ({pages: 28})
            $table->ulid('actor_id')->nullable();
            $table->boolean('client_visible')->default(false);
            $table->timestamps();

            $table->index(['site_id', 'occurred_at']);
        });

        Schema::create('site_monthly_snapshots', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('site_id')->constrained()->cascadeOnDelete();
            $table->date('month');                        // first day of the month
            $table->json('counts');                       // the headline strip: work done that month
            $table->json('metrics');                      // the movement: start / end / delta per metric
            $table->unsignedInteger('timeline_entries')->default(0);
            $table->timestamp('closed_at');               // when the month was frozen
            $table->timestamps();

            $table->unique(['site_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_monthly_snapshots');
        Schema::dropIfExists('activity_events');
    }
};
