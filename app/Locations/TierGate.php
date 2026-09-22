<?php

namespace App\Locations;

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Enums\SizeTier;
use App\Metrics\UrlNormalizer;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\PageIndexState;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The tiered-rollout gate (advisory). Towns build band-by-band WITHIN a market — the market's
 * {@see CoverageBand} chain: major → large → medium → small → ungrouped for a county-drawn territory,
 * ring5 → ring10 → ring15 for a distance-drawn one — and a band is buildable only once the band ABOVE it
 * clears an indexing threshold: build the first band, get it indexed, then let its internal links pull
 * the next band in faster. The gate is consulted by {@see LocalRelevance::dripGraduate()} so it shapes what the build plan
 * SELECTS, not just what a screen shows. It never hard-stops an operator: a manual town toggle overrides it.
 *
 * "Buildable" for a (market, tier): the nearest NON-EMPTY tier above it in that market has ≥ `indexed_pct`
 * of its BUILT pages indexed, OR its most recent page was submitted ≥ `stale_days` ago (the time escape so
 * one stubborn unindexed page can't hold a tier hostage) — whichever comes first. The top non-empty tier in
 * a market is always buildable. Market = the serving Location (a town's first `source_location_ids`).
 *
 * Reads are memoized per site so a full drip pass (one call per reserve town) loads the coverage set, the
 * built town pages, and the indexed-URL set once. All queries drop `SiteScope` (this runs off-request).
 */
class TierGate
{
    /** @var array<string, Collection<int, CoverageArea>> */
    private array $coverage = [];

    /** @var array<string, Collection<int, Content>> town pages (kind=page, page_type=location, location-less) */
    private array $builtPages = [];

    /** @var array<string, array<string, true>> the site's indexed (PASS) url_normalized set */
    private array $indexedUrls = [];

    /** @var array<string, string> normalized town name => band, per site */
    private array $tierByTown = [];

    /** @var array<string, Collection<int, Location>> the site's locations keyed by id (the per-market band chain) */
    private array $locations = [];

    /** May this reserve town be auto-selected for building now? The drip gate. */
    public function allowsTown(Site $site, CoverageArea $town): bool
    {
        return $this->status($site, $this->marketOf($town), $this->bandOf($town))->buildable;
    }

    /**
     * Buildability of one (market, band) with a human reason and the band-above figures behind it.
     * `$band` is a {@see CoverageBand} key (a SizeTier value, a ring, or null/'ungrouped'); `$marketId`
     * null = gate site-wide (a town with no serving location — read as a county chain).
     */
    public function status(Site $site, ?string $marketId, SizeTier|string|null $band): TierStatus
    {
        $band = $band instanceof SizeTier ? $band->value : ($band ?? CoverageBand::UNGROUPED);
        $chain = $this->chain($site, $marketId);
        $above = $this->nearestNonEmptyAbove($site, $marketId, $band, $chain);
        if ($above === null) {
            return TierStatus::buildable('Top tier — always buildable');
        }
        $aboveLabel = CoverageBand::label($above, $chain);

        $built = $this->builtInMarketTier($site, $marketId, $above);
        $builtCount = $built->count();
        if ($builtCount === 0) {
            return TierStatus::locked("Build {$aboveLabel} first");
        }

        $indexed = $this->indexedCount($site, $built);
        $cfg = $site->tierGate();
        $pct = $indexed / $builtCount;

        if ($pct >= $cfg['indexed_pct']) {
            return TierStatus::buildable(sprintf('%s %d%% indexed', $aboveLabel, (int) round($pct * 100)), $builtCount, $indexed);
        }

        $staleDays = $this->staleDays($built);
        if ($staleDays !== null && $staleDays >= $cfg['stale_days']) {
            return TierStatus::buildable(sprintf('%d days since last %s submission', $staleDays, $aboveLabel), $builtCount, $indexed);
        }

        $need = (int) ceil($builtCount * $cfg['indexed_pct']);
        $toGo = max(1, $need - $indexed);

        return TierStatus::locked(
            sprintf('Unlocks when %s is %d%% indexed — %d to go', $aboveLabel, (int) round($cfg['indexed_pct'] * 100), $toGo),
            $builtCount,
            $indexed,
        );
    }

    /**
     * The nearest band above `$band` that has ANY coverage in the market, or null when `$band` is the top.
     * The ungrouped band is never "above" anything.
     *
     * @param  list<string>  $chain
     */
    private function nearestNonEmptyAbove(Site $site, ?string $marketId, string $band, array $chain): ?string
    {
        $idx = array_search($band, $chain, true);
        $idx = $idx === false ? count($chain) - 1 : $idx; // defensive — an unknown band reads as the bottom
        $present = $this->bandsPresentInMarket($site, $marketId);

        for ($j = $idx - 1; $j >= 0; $j--) {
            $candidate = $chain[$j];
            if ($candidate !== CoverageBand::UNGROUPED && isset($present[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return list<string> the market's band chain — its serving location's mode decides */
    private function chain(Site $site, ?string $marketId): array
    {
        $location = $marketId === null ? null : ($this->locations($site)[$marketId] ?? null);

        return CoverageBand::chain($location);
    }

    /** @return array<string, true> the bands that have ≥1 coverage row in this market */
    private function bandsPresentInMarket(Site $site, ?string $marketId): array
    {
        $present = [];
        foreach ($this->coverageInMarket($site, $marketId) as $area) {
            $band = $this->bandOf($area);
            if ($band !== CoverageBand::UNGROUPED) {
                $present[$band] = true;
            }
        }

        return $present;
    }

    /** @return Collection<int, CoverageArea> */
    private function coverageInMarket(Site $site, ?string $marketId): Collection
    {
        $all = $this->coverage($site);
        if ($marketId === null) {
            return $all;
        }

        return $all->filter(fn (CoverageArea $a): bool => in_array($marketId, (array) $a->source_location_ids, true))->values();
    }

    /** @return Collection<int, Content> built town pages of `$band` in this market */
    private function builtInMarketTier(Site $site, ?string $marketId, string $band): Collection
    {
        $tierByTown = $this->tierByTown($site);

        return $this->builtPages($site)
            ->filter(function (Content $c) use ($marketId, $band, $tierByTown): bool {
                if ($marketId !== null && (string) $c->parent_location_id !== $marketId) {
                    return false;
                }

                return ($tierByTown[$this->townKey((string) $c->title)] ?? null) === $band;
            })
            ->values();
    }

    /** @param  Collection<int, Content>  $pages */
    private function indexedCount(Site $site, Collection $pages): int
    {
        $home = rtrim((string) $site->domain_url, '/');
        $passUrls = $this->indexedUrls($site);

        return $pages->filter(function (Content $c) use ($home, $passUrls): bool {
            $url = UrlNormalizer::url($home.'/'.ltrim((string) $c->slug, '/'));

            return isset($passUrls[$url]);
        })->count();
    }

    /** Days since the most recent IndexNow submission in the set, or null when nothing was submitted. */
    private function staleDays(Collection $pages): ?int
    {
        $latest = $pages
            ->map(fn (Content $c): ?Carbon => $c->indexnow_submitted_at)
            ->filter()
            ->max();

        return $latest instanceof Carbon ? (int) $latest->diffInDays(now()) : null;
    }

    private function marketOf(CoverageArea $town): ?string
    {
        $ids = (array) $town->source_location_ids;

        return isset($ids[0]) ? (string) $ids[0] : null;
    }

    /** The town's roll-out band; a row with none is ungrouped. */
    private function bandOf(CoverageArea $town): string
    {
        return is_string($town->band) && $town->band !== '' ? $town->band : CoverageBand::UNGROUPED;
    }

    /** Normalize a town name for matching (drop a trailing ", ST", lower) — mirrors the town sweeper/directory. */
    private function townKey(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', trim($name))));
    }

    // ---- memoized per-site loads -------------------------------------------------------------------

    /** @return Collection<int, CoverageArea> */
    private function coverage(Site $site): Collection
    {
        return $this->coverage[$site->id] ??= CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get(['id', 'name', 'size_tier', 'band', 'source_location_ids']);
    }

    /** @return Collection<int, Location> keyed by id */
    private function locations(Site $site): Collection
    {
        return $this->locations[$site->id] ??= Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get(['id', 'coverage_mode', 'coverage_radius'])
            ->keyBy('id');
    }

    /** @return Collection<int, Content> */
    private function builtPages(Site $site): Collection
    {
        return $this->builtPages[$site->id] ??= Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->whereNull('location_id')
            ->whereNotNull('parent_location_id')
            ->get(['id', 'title', 'slug', 'parent_location_id', 'indexnow_submitted_at']);
    }

    /** @return array<string, true> */
    private function indexedUrls(Site $site): array
    {
        if (isset($this->indexedUrls[$site->id])) {
            return $this->indexedUrls[$site->id];
        }

        $set = [];
        $urls = PageIndexState::query()
            ->where('site_id', $site->id)
            ->where('index_verdict', 'PASS')
            ->pluck('url_normalized');
        foreach ($urls as $url) {
            $set[(string) $url] = true;
        }

        return $this->indexedUrls[$site->id] = $set;
    }

    /** @return array<string, string> normalized town name => band */
    private function tierByTown(Site $site): array
    {
        return $this->tierByTown[$site->id] ??= $this->coverage($site)
            ->filter(fn (CoverageArea $a): bool => $this->bandOf($a) !== CoverageBand::UNGROUPED)
            ->mapWithKeys(fn (CoverageArea $a): array => [$this->townKey((string) $a->name) => $this->bandOf($a)])
            ->all();
    }
}
