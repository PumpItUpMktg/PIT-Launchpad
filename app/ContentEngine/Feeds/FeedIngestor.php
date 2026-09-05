<?php

namespace App\ContentEngine\Feeds;

use App\ContentEngine\CandidateFunnel;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Source;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * The uniform ingest loop — origin-blind by design. For each active feed it
 * fetches (host-branched, via FeedFetcher), records health telemetry, then routes
 * the items through the same §6a candidate funnel, passing the feed's silo as a
 * routing backstop. The only origin-dependent decisions live in FeedFetcher; from
 * here down, generated and client feeds are identical.
 *
 * Each feed returns a per-stage FeedIngestReport (fetched → prefiltered-out →
 * deduped → score-rejected → routed/parked) — logged and surfaced — so a
 * 0-candidates run says exactly where the items went instead of vanishing.
 *
 * FAIRNESS + COMPLETION (blog relay PR 1). The scheduled run ({@see ingestDue()})
 * processes feeds STALEST-FIRST (last_fetched_at ascending, never-fetched first)
 * across ALL tenants, bounded by a wall-clock deadline. Before the deadline it
 * covers the whole due set; once spent it stops cleanly, leaving the remainder for
 * the next hourly tick — which, ordered the same way, picks up exactly the feeds
 * this run didn't reach. That drains the backlog fairly instead of re-chewing a
 * fixed head every partial run (the stall: an unbounded pass over a large
 * keyword×geo fan-out overran the hour, the next tick was skipped by
 * withoutOverlapping, and the tail feeds never ran).
 */
class FeedIngestor
{
    public function __construct(
        private readonly FeedFetcher $fetcher,
        private readonly CandidateFunnel $funnel,
    ) {}

    public function ingestFeed(Source $feed): FeedIngestReport
    {
        $started = microtime(true);
        $report = $this->fetchAndRoute($feed)
            ->withDuration((int) round((microtime(true) - $started) * 1000));

        Log::info('feed.ingest.report', $report->toLog());

        return $report;
    }

    private function fetchAndRoute(Source $feed): FeedIngestReport
    {
        $label = $feed->label ?? $feed->url ?? $feed->id;
        $result = $this->fetcher->fetch($feed);
        $this->recordHealth($feed, $result);

        if (! $result->ok()) {
            Log::warning('feed.ingest.skipped', ['feed_id' => $feed->id, 'error' => $result->error]);

            return FeedIngestReport::unfetched($feed->id, $label, $result->error);
        }

        $site = Site::query()->findOrFail($feed->site_id);
        $funnel = $this->funnel->process($site, $result->items, $feed->silo_id);

        return FeedIngestReport::fromFunnel($feed->id, $label, count($result->items), $funnel);
    }

    /**
     * Ingest every active feed for a single site, unbounded (the --site / manual
     * path). Fair ordering still applies; no deadline unless one is passed.
     *
     * @return array{feeds: int, skipped: int, fetched: int, prefiltered_out: int, deduped: int, score_rejected: int, routed: int, parked: int, refresh_marked: int, unhealthy: int, elapsed_ms: int, reports: list<FeedIngestReport>}
     */
    public function ingestSite(Site $site, ?float $deadline = null): array
    {
        return $this->run($this->dueFeeds($site->id), $deadline);
    }

    /**
     * The scheduled entrypoint: ingest due feeds across ALL tenants (or one, via
     * $siteId), stalest-first, bounded by a wall-clock budget. When the budget is
     * spent the run stops and the untouched feeds — being the stalest — lead the
     * next tick.
     *
     * @return array{feeds: int, skipped: int, fetched: int, prefiltered_out: int, deduped: int, score_rejected: int, routed: int, parked: int, refresh_marked: int, unhealthy: int, elapsed_ms: int, reports: list<FeedIngestReport>}
     */
    public function ingestDue(?float $budgetSeconds = null, ?string $siteId = null): array
    {
        $deadline = $budgetSeconds !== null ? microtime(true) + $budgetSeconds : null;

        return $this->run($this->dueFeeds($siteId), $deadline);
    }

    /**
     * Active feeds (generated + client) with a URL, ordered STALEST-FIRST:
     * never-fetched (last_fetched_at null) lead, then oldest last_fetched_at. The
     * `is null desc` primary key is portable across pgsql + sqlite (avoids the
     * dialect-specific NULLS FIRST).
     *
     * @return Collection<int, Source>
     */
    private function dueFeeds(?string $siteId): Collection
    {
        return Source::withoutGlobalScope(SiteScope::class)
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->where('enabled', true)
            ->whereNotNull('url')
            ->orderByRaw('last_fetched_at is null desc')
            ->orderBy('last_fetched_at')
            ->get();
    }

    /**
     * @param  Collection<int, Source>  $feeds
     * @return array{feeds: int, skipped: int, fetched: int, prefiltered_out: int, deduped: int, score_rejected: int, routed: int, parked: int, refresh_marked: int, unhealthy: int, elapsed_ms: int, reports: list<FeedIngestReport>}
     */
    private function run(Collection $feeds, ?float $deadline): array
    {
        $started = microtime(true);
        /** @var list<FeedIngestReport> $reports */
        $reports = [];
        $skipped = 0;

        foreach ($feeds->values() as $i => $feed) {
            // Stop before starting a feed we can't finish inside the budget — the untouched, stalest feeds
            // lead the next tick. (The check is between feeds, so one in-flight feed may run slightly over.)
            if ($deadline !== null && microtime(true) >= $deadline) {
                $skipped = $feeds->count() - $i;
                Log::warning('feed.ingest.deadline', ['processed' => $i, 'skipped' => $skipped]);
                break;
            }
            $reports[] = $this->ingestFeed($feed);
        }

        $sum = fn (callable $field): int => array_sum(array_map($field, $reports));

        return [
            'feeds' => count($reports),
            'skipped' => $skipped,
            'fetched' => $sum(fn (FeedIngestReport $r) => $r->fetched),
            'prefiltered_out' => $sum(fn (FeedIngestReport $r) => $r->prefilteredOut),
            'deduped' => $sum(fn (FeedIngestReport $r) => $r->deduped),
            'score_rejected' => $sum(fn (FeedIngestReport $r) => $r->scoreRejected),
            'routed' => $sum(fn (FeedIngestReport $r) => $r->routed),
            'parked' => $sum(fn (FeedIngestReport $r) => $r->parked),
            'refresh_marked' => $sum(fn (FeedIngestReport $r) => $r->refreshMarked),
            'unhealthy' => count(array_filter($reports, fn (FeedIngestReport $r) => $r->error !== null)),
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            'reports' => $reports,
        ];
    }

    private function recordHealth(Source $feed, FeedFetchResult $result): void
    {
        $feed->forceFill([
            'last_fetched_at' => now(),
            'last_error' => $result->ok() ? null : $result->error,
            'last_item_at' => $result->items !== [] ? now() : $feed->last_item_at,
        ])->save();
    }
}
