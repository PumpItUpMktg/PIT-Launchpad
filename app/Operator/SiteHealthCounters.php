<?php

namespace App\Operator;

use App\Console\Commands\ReconcileSiteCountersCommand;
use App\Console\Commands\ResetPublishCommand;
use App\Observers\ConnectionObserver;
use App\Observers\ContentObserver;
use Illuminate\Support\Facades\DB;

/**
 * Maintains the persisted portfolio-health counters on `sites` (the /admin/sites slow-aggregate fix).
 * Every counter is recomputed **from the source of truth** — a handful of site-scoped `COUNT`s — rather
 * than delta-adjusted, so it is idempotent: a double-fired model event, an overlapping delete/force-delete,
 * or a re-run of the reconcile command all converge on the correct value instead of drifting.
 *
 * Called from {@see ContentObserver} / {@see ConnectionObserver} on every
 * status/flag transition (the common single-model path), from the three bulk `whereIn(...)->update()` call
 * sites in {@see ResetPublishCommand} (which bypass model events), and wholesale from
 * {@see ReconcileSiteCountersCommand} (the scheduled drift net).
 *
 * Reads/writes go through the query builder ({@see DB}) so they bypass the `SiteScope` global scope (these
 * run outside a tenant request) and never re-fire model events (no observer recursion).
 */
class SiteHealthCounters
{
    /**
     * The persisted content-status tallies: ContentStatus value => the `sites` column it feeds. Matches the
     * board's old `withCount` semantics, INCLUDING excluding soft-deleted rows (see {@see recomputeContent}).
     *
     * @var array<string, string>
     */
    public const CONTENT_COUNTERS = [
        'needs_review' => 'review_backlog_count',
        'render_failed' => 'render_failed_count',
        'publish_failed' => 'publish_failed_count',
    ];

    /** Recompute the three content-status counters for one site and persist them. */
    public function recomputeContent(string $siteId): void
    {
        $update = [];
        foreach (self::CONTENT_COUNTERS as $status => $column) {
            $update[$column] = DB::table('contents')
                ->where('site_id', $siteId)
                ->whereNull('deleted_at') // exclude soft-deleted, matching the relation-scoped withCount
                ->where('status', $status)
                ->count();
        }

        DB::table('sites')->where('id', $siteId)->update($update);
    }

    /** Recompute the compromised-connection counter for one site and persist it. */
    public function recomputeCompromised(string $siteId): void
    {
        $count = DB::table('connections')
            ->where('site_id', $siteId)
            ->where('compromised', true)
            ->count();

        DB::table('sites')->where('id', $siteId)->update(['compromised_count' => $count]);
    }

    /** Recompute every counter for one site (used by the reconcile command). */
    public function recomputeAll(string $siteId): void
    {
        $this->recomputeContent($siteId);
        $this->recomputeCompromised($siteId);
    }

    /**
     * Recompute every counter for every site — the reconcile command's whole-portfolio pass. Returns the
     * number of sites reconciled. Iterates ids (not models) so no global scope or model events are involved.
     */
    public function recomputeAllSites(): int
    {
        $ids = DB::table('sites')->pluck('id');

        foreach ($ids as $id) {
            $this->recomputeAll((string) $id);
        }

        return $ids->count();
    }
}
