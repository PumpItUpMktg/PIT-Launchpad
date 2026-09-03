<?php

namespace App\Locations;

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Enums\SizeTier;
use App\Metrics\UrlNormalizer;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\PageIndexState;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The tiered-rollout gate (advisory). Towns build tier-by-tier WITHIN a market — major → large → medium →
 * small → ungrouped(null) — and a tier is buildable only once the tier ABOVE it clears an indexing
 * threshold: build the largest tier, get it indexed, then let its internal links pull the next tier in
 * faster. The gate is consulted by {@see LocalRelevance::dripGraduate()} so it shapes what the build plan
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
    /** Buildability order — a tier waits on the nearest non-empty tier to its LEFT. Null = ungrouped, last. */
    private const CHAIN = [SizeTier::Major, SizeTier::Large, SizeTier::Medium, SizeTier::Small, null];

    /** @var array<string, Collection<int, CoverageArea>> */
    private array $coverage = [];

    /** @var array<string, Collection<int, Content>> town pages (kind=page, page_type=location, location-less) */
    private array $builtPages = [];

    /** @var array<string, array<string, true>> the site's indexed (PASS) url_normalized set */
    private array $indexedUrls = [];

    /** @var array<string, string> normalized town name => size_tier value, per site */
    private array $tierByTown = [];

    /** May this reserve town be auto-selected for building now? The drip gate. */
    public function allowsTown(Site $site, CoverageArea $town): bool
    {
        return $this->status($site, $this->marketOf($town), $this->tierOf($town))->buildable;
    }

    /**
     * Buildability of one (market, tier) with a human reason and the tier-above figures behind it.
     * `$marketId` null = gate site-wide (a town with no serving location).
     */
    public function status(Site $site, ?string $marketId, ?SizeTier $tier): TierStatus
    {
        $above = $this->nearestNonEmptyAbove($site, $marketId, $tier);
        if ($above === null) {
            return TierStatus::buildable('Top tier — always buildable');
        }

        $built = $this->builtInMarketTier($site, $marketId, $above);
        $builtCount = $built->count();
        if ($builtCount === 0) {
            return TierStatus::locked("Build {$above->label()} first");
        }

        $indexed = $this->indexedCount($site, $built);
        $cfg = $site->tierGate();
        $pct = $indexed / $builtCount;

        if ($pct >= $cfg['indexed_pct']) {
            return TierStatus::buildable(sprintf('%s %d%% indexed', $above->label(), (int) round($pct * 100)), $builtCount, $indexed);
        }

        $staleDays = $this->staleDays($built);
        if ($staleDays !== null && $staleDays >= $cfg['stale_days']) {
            return TierStatus::buildable(sprintf('%d days since last %s submission', $staleDays, $above->label()), $builtCount, $indexed);
        }

        $need = (int) ceil($builtCount * $cfg['indexed_pct']);
        $toGo = max(1, $need - $indexed);

        return TierStatus::locked(
            sprintf('Unlocks when %s is %d%% indexed — %d to go', $above->label(), (int) round($cfg['indexed_pct'] * 100), $toGo),
            $builtCount,
            $indexed,
        );
    }

    /** The nearest tier above `$tier` that has ANY coverage in the market, or null when `$tier` is the top. */
    private function nearestNonEmptyAbove(Site $site, ?string $marketId, ?SizeTier $tier): ?SizeTier
    {
        $idx = $this->chainIndex($tier);
        $present = $this->tiersPresentInMarket($site, $marketId);

        for ($j = $idx - 1; $j >= 0; $j--) {
            $candidate = self::CHAIN[$j];
            if ($candidate !== null && isset($present[$candidate->value])) {
                return $candidate;
            }
        }

        return null;
    }

    private function chainIndex(?SizeTier $tier): int
    {
        foreach (self::CHAIN as $i => $t) {
            if ($t === $tier) {
                return $i;
            }
        }

        return count(self::CHAIN) - 1; // defensive — treat an unknown tier as the bottom (ungrouped)
    }

    /** @return array<string, true> the size_tier values that have ≥1 coverage row in this market */
    private function tiersPresentInMarket(Site $site, ?string $marketId): array
    {
        $present = [];
        foreach ($this->coverageInMarket($site, $marketId) as $area) {
            if (is_string($area->size_tier) && $area->size_tier !== '') {
                $present[$area->size_tier] = true;
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

    /** @return Collection<int, Content> built town pages of `$tier` in this market */
    private function builtInMarketTier(Site $site, ?string $marketId, SizeTier $tier): Collection
    {
        $tierByTown = $this->tierByTown($site);

        return $this->builtPages($site)
            ->filter(function (Content $c) use ($marketId, $tier, $tierByTown): bool {
                if ($marketId !== null && (string) $c->parent_location_id !== $marketId) {
                    return false;
                }

                return ($tierByTown[$this->townKey((string) $c->title)] ?? null) === $tier->value;
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

    private function tierOf(CoverageArea $town): ?SizeTier
    {
        return is_string($town->size_tier) ? SizeTier::tryFrom($town->size_tier) : null;
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
            ->get(['id', 'name', 'size_tier', 'source_location_ids']);
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

    /** @return array<string, string> normalized town name => size_tier value */
    private function tierByTown(Site $site): array
    {
        return $this->tierByTown[$site->id] ??= $this->coverage($site)
            ->filter(fn (CoverageArea $a): bool => is_string($a->size_tier) && $a->size_tier !== '')
            ->mapWithKeys(fn (CoverageArea $a): array => [$this->townKey((string) $a->name) => (string) $a->size_tier])
            ->all();
    }
}
