<?php

namespace App\ContentEngine\Feeds;

use App\ContentEngine\CandidateFunnel;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Models\Source;
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
 */
class FeedIngestor
{
    public function __construct(
        private readonly FeedFetcher $fetcher,
        private readonly CandidateFunnel $funnel,
    ) {}

    public function ingestFeed(Source $feed): FeedIngestReport
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

        $report = FeedIngestReport::fromFunnel($feed->id, $label, count($result->items), $funnel);
        Log::info('feed.ingest.report', $report->toLog());

        return $report;
    }

    /**
     * Ingest every active feed for a site. Paced by the caller — the keyword×geo
     * fan-out can be large.
     *
     * @return array{feeds: int, fetched: int, prefiltered_out: int, deduped: int, score_rejected: int, routed: int, parked: int, refresh_marked: int, unhealthy: int, reports: list<FeedIngestReport>}
     */
    public function ingestSite(Site $site): array
    {
        $feeds = Source::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('enabled', true)
            ->whereNotNull('url')
            ->get();

        /** @var list<FeedIngestReport> $reports */
        $reports = [];
        foreach ($feeds as $feed) {
            $reports[] = $this->ingestFeed($feed);
        }

        $sum = fn (callable $field): int => array_sum(array_map($field, $reports));

        return [
            'feeds' => $feeds->count(),
            'fetched' => $sum(fn (FeedIngestReport $r) => $r->fetched),
            'prefiltered_out' => $sum(fn (FeedIngestReport $r) => $r->prefilteredOut),
            'deduped' => $sum(fn (FeedIngestReport $r) => $r->deduped),
            'score_rejected' => $sum(fn (FeedIngestReport $r) => $r->scoreRejected),
            'routed' => $sum(fn (FeedIngestReport $r) => $r->routed),
            'parked' => $sum(fn (FeedIngestReport $r) => $r->parked),
            'refresh_marked' => $sum(fn (FeedIngestReport $r) => $r->refreshMarked),
            'unhealthy' => count(array_filter($reports, fn (FeedIngestReport $r) => $r->error !== null)),
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
