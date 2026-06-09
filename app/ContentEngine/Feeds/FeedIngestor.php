<?php

namespace App\ContentEngine\Feeds;

use App\ContentEngine\CandidateFunnel;
use App\ContentEngine\FunnelResult;
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
 */
class FeedIngestor
{
    public function __construct(
        private readonly FeedFetcher $fetcher,
        private readonly CandidateFunnel $funnel,
    ) {}

    public function ingestFeed(Source $feed): FunnelResult
    {
        $result = $this->fetcher->fetch($feed);
        $this->recordHealth($feed, $result);

        if (! $result->ok()) {
            Log::warning('feed.ingest.skipped', ['feed_id' => $feed->id, 'error' => $result->error]);

            return new FunnelResult([], [], [], [], []);
        }

        $site = Site::query()->findOrFail($feed->site_id);

        return $this->funnel->process($site, $result->items, $feed->silo_id);
    }

    /**
     * Ingest every active feed for a site. Paced by the caller — the keyword×geo
     * fan-out can be large.
     *
     * @return array{feeds: int, candidates: int, parked: int, unhealthy: int}
     */
    public function ingestSite(Site $site): array
    {
        $feeds = Source::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('enabled', true)
            ->whereNotNull('url')
            ->get();

        $candidates = 0;
        $parked = 0;
        $unhealthy = 0;

        foreach ($feeds as $feed) {
            $result = $this->ingestFeed($feed);
            $candidates += count($result->created);
            $parked += count($result->parked);
            if (filled($feed->last_error)) {
                $unhealthy++;
            }
        }

        return [
            'feeds' => $feeds->count(),
            'candidates' => $candidates,
            'parked' => $parked,
            'unhealthy' => $unhealthy,
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
