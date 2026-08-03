<?php

namespace App\KeywordGenerator\Pipeline;

use App\Enums\MarketTier;
use App\Enums\PipelineTrigger;
use App\Enums\SampleType;
use App\Integrations\LocalGrid\LocalGridProvider;
use App\Integrations\Serp\SerpProvider;
use App\KeywordGenerator\Cadence\CadenceScheduler;
use App\KeywordGenerator\Cadence\SamplingTask;
use App\KeywordGenerator\Cadence\Tiering;
use App\KeywordGenerator\Discovery\SiloKeywordGenerator;
use App\KeywordGenerator\Tracking\PositionTracker;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\PositionSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operator\Controls\BudgetControl;
use Illuminate\Database\Eloquent\Collection;
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
    private const LANE_ORGANIC = 'organic';

    private const LANE_LOCAL = 'local';

    public function __construct(
        private readonly KeywordPipeline $pipeline,
        private readonly PositionTracker $tracker,
        private readonly SerpProvider $serp,
        private readonly LocalGridProvider $grid,
        private readonly SiloKeywordGenerator $generator,
        private readonly Tiering $tiering,
        private readonly CadenceScheduler $scheduler,
        private readonly BudgetControl $budget,
        private readonly int $trackingCadenceDays,
        private readonly int $discoveryCadenceDays,
    ) {}

    /**
     * @param  bool  $generate  When true, first pull FRESH keyword ideas per silo (real provider calls)
     *                          before scoring — the on-demand path fills empty silos. Left false on the
     *                          scheduled cadence so a routine sweep only re-scores (no new API spend).
     */
    public function refresh(Site $site, PipelineTrigger $trigger, bool $force = false, bool $generate = false): SitePipelineRefreshResult
    {
        $startedAt = now();
        $discoveryRan = false;
        $keywordsGenerated = 0;
        $keywordsScored = 0;
        $trackingRan = false;
        $snapshots = 0;

        if ($generate && ($force || $this->dueForDiscovery($site))) {
            $keywordsGenerated = $this->generator->generate($site);
        }

        if ($force || $this->dueForDiscovery($site)) {
            $keywordsScored = count($this->pipeline->run($site)->scored);
            $discoveryRan = true;
        }

        if ($force || $this->dueForTracking($site)) {
            $snapshots = $this->track($site, $force);
            $trackingRan = true;
        }

        Log::info('§5 pipeline refresh', [
            'site_id' => $site->id,
            'trigger' => $trigger->value,
            'discovery_ran' => $discoveryRan,
            'keywords_generated' => $keywordsGenerated,
            'keywords_scored' => $keywordsScored,
            'tracking_ran' => $trackingRan,
            'snapshots' => $snapshots,
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
        ]);

        return new SitePipelineRefreshResult($discoveryRan, $keywordsScored, $trackingRan, $snapshots, $keywordsGenerated);
    }

    /**
     * On-demand positions-only pull: re-sample EVERY scored keyword's rankings now (organic SERP +
     * the priority-market local grid), bypassing the cadence and budget gates — the operator's
     * explicit "refresh rankings now" button. Unlike {@see refresh()} it does NOT discover or score
     * new keywords, so its DataForSEO spend is bounded and estimable up front
     * ({@see PositionPullEstimator}): one organic SERP task per keyword + grid_size² Google Maps
     * tasks per keyword. In standard mode the tasks are posted here and the snapshot finalizes on the
     * IngestSerpTasks sweep + a later read.
     *
     * @return int snapshots written this run
     */
    public function trackNow(Site $site): int
    {
        return $this->track($site, force: true);
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
     * Two-lane sweep over the site's tracked (scored) keywords: organic rank for the site's own
     * domain, plus the priority-market local grid. Records only real signal — a query the site
     * doesn't rank for, or a not-yet-collected standard-mode grid, writes no snapshot.
     *
     * The SCHEDULED path is tiered + budget-capped: each keyword is sampled at its tier's cadence
     * (money/high-opportunity + operator-priority targets frequently, low-value ones rarely) and the
     * run is trimmed to the tenant's REMAINING monthly budget — dropping low tiers / coverage first,
     * never an operator-priority target ({@see Tiering}, {@see CadenceScheduler}, {@see BudgetControl}).
     * A tenant with no `budget_ceiling` set is uncapped (still tiered). The operator FORCE path (the
     * on-demand command) bypasses both — it re-samples every scored keyword now.
     *
     * @return int snapshots written
     */
    private function track(Site $site, bool $force): int
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

        $sample = $force
            ? $this->allTasks($keywords, $host, $priorityMarket)
            : $this->scheduledTasks($site, $keywords, $host, $priorityMarket);

        $snapshots = 0;
        foreach ($sample as [$keyword, $lane]) {
            if ($lane === self::LANE_ORGANIC && $host !== null) {
                $owned = $this->serp->results($keyword->query)->ownedBy($host);
                if ($owned !== []) {
                    usort($owned, fn ($a, $b) => $a->position <=> $b->position);
                    $this->tracker->recordOrganic($keyword, $owned[0]->position, $owned[0]->url);
                    $snapshots++;
                }
            } elseif ($lane === self::LANE_LOCAL && $priorityMarket !== null) {
                $grid = $this->grid->grid($keyword->query, $priorityMarket->id);
                if ($grid->coverage > 0.0 || $grid->packCompetitors !== []) {
                    $this->tracker->recordLocalGrid($keyword, $priorityMarket, $grid);
                    $snapshots++;
                }
            }
        }

        return $snapshots;
    }

    /**
     * Force path: every scored keyword, both lanes — today's exhaustive behavior, unchanged.
     *
     * @param  Collection<int, Keyword>  $keywords
     * @return list<array{0: Keyword, 1: string}>
     */
    private function allTasks(Collection $keywords, ?string $host, ?Market $priorityMarket): array
    {
        $out = [];
        foreach ($keywords as $keyword) {
            if ($host !== null) {
                $out[] = [$keyword, self::LANE_ORGANIC];
            }
            if ($priorityMarket !== null) {
                $out[] = [$keyword, self::LANE_LOCAL];
            }
        }

        return $out;
    }

    /**
     * Scheduled path: tier each keyword by its opportunity rank within the site (scale-independent —
     * top → A … bottom → C; operator-priority targets forced to the top), keep only the ones DUE for
     * their tier's cadence, then trim to the tenant's remaining monthly budget.
     *
     * @param  Collection<int, Keyword>  $keywords
     * @return list<array{0: Keyword, 1: string}>
     */
    private function scheduledTasks(Site $site, Collection $keywords, ?string $host, ?Market $priorityMarket): array
    {
        $ranked = $keywords->sortByDesc(fn (Keyword $k): float => (float) ($k->opportunity_score ?? 0.0))->values();
        $count = $ranked->count();

        /** @var list<SamplingTask> $tasks */
        $tasks = [];
        /** @var array<string, array{0: Keyword, 1: string}> $byRef */
        $byRef = [];

        foreach ($ranked as $i => $keyword) {
            $forced = (int) ($keyword->priority ?? 0) > 0;
            // Opportunity percentile in [0,1] (best = 1); operator-priority pins it to the top.
            $value = $forced ? 1.0 : ($count <= 1 ? 1.0 : 1.0 - ($i / ($count - 1)));

            if ($host !== null) {
                $tier = $this->tiering->tierFor($value);
                $cadence = $this->tiering->cadenceDays($tier, SampleType::Positions);
                if ($this->dueForKeyword($keyword, null, $cadence)) {
                    $ref = 'org:'.$keyword->id;
                    $tasks[] = new SamplingTask($ref, SampleType::Positions, $tier, $cadence, 1.0, forced: $forced);
                    $byRef[$ref] = [$keyword, self::LANE_ORGANIC];
                }
            }

            if ($priorityMarket !== null) {
                $tier = $this->tiering->tierFor($value, MarketTier::Priority);
                $cadence = $this->tiering->cadenceDays($tier, SampleType::Positions);
                if ($this->dueForKeyword($keyword, $priorityMarket->id, $cadence)) {
                    $ref = 'loc:'.$keyword->id;
                    $tasks[] = new SamplingTask($ref, SampleType::Positions, $tier, $cadence, 1.0, marketId: $priorityMarket->id, forced: $forced);
                    $byRef[$ref] = [$keyword, self::LANE_LOCAL];
                }
            }
        }

        // Trim to the remaining monthly budget (null ceiling → uncapped). Forced targets always kept.
        $remaining = $this->budget->remaining($site);
        $included = $remaining === null ? $tasks : $this->scheduler->fit($tasks, (float) $remaining)->included;

        return array_map(fn (SamplingTask $t): array => $byRef[$t->ref], $included);
    }

    /** A keyword+lane is due when it has no snapshot in that lane within its tier's cadence window. */
    private function dueForKeyword(Keyword $keyword, ?string $marketId, int $cadenceDays): bool
    {
        $query = PositionSnapshot::withoutGlobalScope(SiteScope::class)->where('keyword_id', $keyword->id);
        $query = $marketId === null ? $query->whereNull('market_id') : $query->where('market_id', $marketId);
        $latest = $query->max('captured_at');

        return $latest === null || Carbon::parse($latest)->lt(now()->subDays($cadenceDays));
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
