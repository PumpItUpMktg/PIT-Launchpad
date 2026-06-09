<?php

namespace App\KeywordGenerator\Pipeline;

use App\Enums\MarketTier;
use App\Enums\PipelineTrigger;
use App\Integrations\LocalGrid\LocalGridProvider;
use App\Integrations\Serp\SerpProvider;
use App\KeywordGenerator\Tracking\PositionTracker;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\PositionSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Drives the §5 work for one site (the missing caller): keyword discovery/scoring
 * (KeywordPipeline) and the two-lane position-tracking sweep (PositionTracker).
 * It does not change §5 internals — it only invokes them, gated by a config-driven
 * cadence read off the durable artifacts each unit produces, so a run never
 * re-spends DataForSEO calls inside the window.
 *
 * Cadence-dedup keys off SUCCESSFUL output: discovery off the newest scored
 * Keyword, tracking off the newest PositionSnapshot. A site with no recent
 * artifact runs — which naturally retries failed/empty runs and sets the next-run
 * clock off real output. The operator action passes force=true to bypass it.
 *
 * Standard-mode SERP tasks are posted here (front half); the existing
 * IngestSerpTasks 5-min sweep collects them (back half) — a later run then reads
 * the cached result and records the snapshot.
 */
class SitePipelineRefresher
{
    public function __construct(
        private readonly KeywordPipeline $pipeline,
        private readonly PositionTracker $tracker,
        private readonly SerpProvider $serp,
        private readonly LocalGridProvider $grid,
        private readonly int $trackingCadenceDays,
        private readonly int $discoveryCadenceDays,
    ) {}

    public function refresh(Site $site, PipelineTrigger $trigger, bool $force = false): SitePipelineRefreshResult
    {
        $startedAt = now();
        $discoveryRan = false;
        $keywordsScored = 0;
        $trackingRan = false;
        $snapshots = 0;

        if ($force || $this->dueForDiscovery($site)) {
            $keywordsScored = count($this->pipeline->run($site)->scored);
            $discoveryRan = true;
        }

        if ($force || $this->dueForTracking($site)) {
            $snapshots = $this->track($site);
            $trackingRan = true;
        }

        Log::info('§5 pipeline refresh', [
            'site_id' => $site->id,
            'trigger' => $trigger->value,
            'discovery_ran' => $discoveryRan,
            'keywords_scored' => $keywordsScored,
            'tracking_ran' => $trackingRan,
            'snapshots' => $snapshots,
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
        ]);

        return new SitePipelineRefreshResult($discoveryRan, $keywordsScored, $trackingRan, $snapshots);
    }

    private function dueForDiscovery(Site $site): bool
    {
        $latest = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereNotNull('opportunity_score')
            ->max('updated_at');

        return $latest === null || Carbon::parse($latest)->lt(now()->subDays($this->discoveryCadenceDays));
    }

    private function dueForTracking(Site $site): bool
    {
        $latest = PositionSnapshot::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->max('captured_at');

        return $latest === null || Carbon::parse($latest)->lt(now()->subDays($this->trackingCadenceDays));
    }

    /**
     * Two-lane sweep over the site's tracked (scored) keywords: organic rank for
     * the site's own domain, plus the priority-market local grid. Records only
     * real signal — a query the site doesn't rank for, or a not-yet-collected
     * standard-mode grid, writes no snapshot.
     */
    private function track(Site $site): int
    {
        $host = $this->host($site->domain_url);

        $keywords = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', 'scored')
            ->get();

        $priorityMarket = Market::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('tier', MarketTier::Priority->value)
            ->first();

        $snapshots = 0;

        foreach ($keywords as $keyword) {
            if ($host !== null) {
                $owned = $this->serp->results($keyword->query)->ownedBy($host);
                if ($owned !== []) {
                    usort($owned, fn ($a, $b) => $a->position <=> $b->position);
                    $best = $owned[0];
                    $this->tracker->recordOrganic($keyword, $best->position, $best->url);
                    $snapshots++;
                }
            }

            if ($priorityMarket !== null) {
                $grid = $this->grid->grid($keyword->query, $priorityMarket->id);
                if ($grid->coverage > 0.0 || $grid->packCompetitors !== []) {
                    $this->tracker->recordLocalGrid($keyword, $priorityMarket, $grid);
                    $snapshots++;
                }
            }
        }

        return $snapshots;
    }

    private function host(?string $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $host = parse_url(str_contains($url, '://') ? $url : 'https://'.$url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
