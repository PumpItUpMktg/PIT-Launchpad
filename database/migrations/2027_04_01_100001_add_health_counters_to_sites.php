<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persisted portfolio-health counters on `sites` (the /admin/sites slow-aggregate fix). The Portfolio
 * board sorted tenants by a correlated `withCount` subquery that COUNT-scanned each site's thousands of
 * `contents` rows for EVERY site before sorting — a 6.4s query. These columns hold the counts as real,
 * incrementally-maintained values so the board reads and sorts a plain column instead (the small `sites`
 * table needs no index for that). Maintenance: `Content`/`Connection` observers adjust them on every
 * status/flag transition; `launchpad:reconcile-site-counters` recomputes from source as the drift net.
 *
 * The counters match the board's old `withCount` semantics exactly, INCLUDING excluding soft-deleted
 * contents. The time-windowed "published this week" count stays a (page-scoped) subquery on the board —
 * a rolling window can't be a static counter — so it is deliberately NOT persisted here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->unsignedInteger('review_backlog_count')->default(0);   // contents status=needs_review
            $table->unsignedInteger('render_failed_count')->default(0);    // contents status=render_failed
            $table->unsignedInteger('publish_failed_count')->default(0);   // contents status=publish_failed
            $table->unsignedInteger('compromised_count')->default(0);      // connections compromised=true
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            $table->dropColumn(['review_backlog_count', 'render_failed_count', 'publish_failed_count', 'compromised_count']);
        });
    }

    /**
     * Seed the counters from the source of truth so existing tenants are correct the instant the board
     * switches to reading them. Portable (query-builder boolean binding); columns default to 0, so a site
     * with no matching rows is already right and never gets an UPDATE.
     */
    private function backfill(): void
    {
        // Content-status tallies — exclude soft-deleted rows, matching the board's relation-scoped withCount.
        $contentColumns = [
            'needs_review' => 'review_backlog_count',
            'render_failed' => 'render_failed_count',
            'publish_failed' => 'publish_failed_count',
        ];
        foreach ($contentColumns as $status => $column) {
            $perSite = DB::table('contents')
                ->whereNull('deleted_at')
                ->where('status', $status)
                ->groupBy('site_id')
                ->select('site_id', DB::raw('COUNT(*) as aggregate'))
                ->pluck('aggregate', 'site_id');

            foreach ($perSite as $siteId => $count) {
                DB::table('sites')->where('id', $siteId)->update([$column => $count]);
            }
        }

        // Compromised connections (connections are hard-deleted — no soft-delete filter).
        $perSite = DB::table('connections')
            ->where('compromised', true)
            ->groupBy('site_id')
            ->select('site_id', DB::raw('COUNT(*) as aggregate'))
            ->pluck('aggregate', 'site_id');

        foreach ($perSite as $siteId => $count) {
            DB::table('sites')->where('id', $siteId)->update(['compromised_count' => $count]);
        }
    }
};
