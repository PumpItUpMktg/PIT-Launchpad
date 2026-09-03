<?php

namespace App\Operate;

use App\Enums\ContentKind;
use App\Enums\PageType;
use App\Enums\SizeTier;
use App\Locations\TierGate;
use App\Metrics\UrlNormalizer;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\PageIndexState;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Publishing\Links\InternalLinkGraph;
use Illuminate\Support\Collection;

/**
 * The tiered-rollout progression read-model: a site's town pages grouped by MARKET (serving Location) →
 * TIER band → town pills, so the operator sees the roll-out state at a glance and where it's stuck. Pure
 * read model over persisted rows + the {@see TierGate} (band buildable/locked state) + one
 * {@see InternalLinkGraph} build for the inbound-link count per town (the signal that predicts indexing
 * better than tier — a page with zero inbound links won't be crawled quickly whatever its tier).
 *
 * Per market: built/served counts and a problem count (built-but-not-indexed) that auto-expands the row.
 * Per tier band: built/served/indexed, a state (complete / indexing+progress / ready / locked-with-reason),
 * and its towns as index-state-coloured pills carrying an inbound-link count. The pill array is shaped so
 * later per-town signals (jobs, reviews, rank, impressions, sessions) slot in without restructuring.
 */
class TierProgression
{
    /** Tier bands top-to-bottom; null = the ungrouped band (no ACS population) shown last. */
    private const TIERS = [SizeTier::Major, SizeTier::Large, SizeTier::Medium, SizeTier::Small, null];

    public function __construct(
        private readonly TierGate $gate,
        private readonly InternalLinkGraph $graph,
    ) {}

    /**
     * @return list<array{
     *     id: string, name: string, built: int, served: int, problem_count: int, has_problem: bool,
     *     tiers: list<array<string, mixed>>
     * }>  most-problematic market first
     */
    public function forSite(Site $site): array
    {
        $graph = $this->graph->build($site);
        $home = rtrim((string) $site->domain_url, '/');
        $coverage = $this->coverage($site);
        $townPages = $this->townPages($site);
        $verdicts = $this->verdicts($site);
        $tierByTown = $this->tierByTown($coverage);

        $markets = [];
        foreach ($this->markets($site) as $location) {
            $market = $this->market($site, $location, $coverage, $townPages, $tierByTown, $verdicts, $graph, $home);
            if ($market['built'] > 0 || $market['served'] > 0) {
                $markets[] = $market;
            }
        }

        usort($markets, fn (array $a, array $b): int => [$b['has_problem'], $b['problem_count'], $a['name']] <=> [$a['has_problem'], $a['problem_count'], $b['name']]);

        return $markets;
    }

    /**
     * @param  Collection<int, CoverageArea>  $coverage
     * @param  Collection<int, Content>  $townPages
     * @param  array<string, string>  $tierByTown
     * @param  array<string, string|null>  $verdicts
     * @return array{id: string, name: string, built: int, served: int, problem_count: int, has_problem: bool, tiers: list<array<string, mixed>>}
     */
    private function market(Site $site, Location $location, Collection $coverage, Collection $townPages, array $tierByTown, array $verdicts, InternalLinkGraph $graph, string $home): array
    {
        $marketId = (string) $location->id;
        $served = $coverage->filter(fn (CoverageArea $a): bool => in_array($marketId, (array) $a->source_location_ids, true));
        $built = $townPages->filter(fn (Content $c): bool => (string) $c->parent_location_id === $marketId);

        $bands = [];
        $builtTotal = 0;
        $servedTotal = 0;
        $problem = 0;
        foreach (self::TIERS as $tier) {
            $tierValue = $tier?->value;
            $servedInTier = $served->filter(fn (CoverageArea $a): bool => $this->tierValue($a) === $tierValue)->count();
            $builtInTier = $built->filter(fn (Content $c): bool => ($tierByTown[$this->townKey((string) $c->title)] ?? null) === $tierValue)->values();

            if ($servedInTier === 0 && $builtInTier->isEmpty()) {
                continue; // an empty tier for this market — nothing to show
            }

            $pills = $builtInTier->map(fn (Content $c): array => [
                'id' => (string) $c->id,
                'name' => $this->townKeyDisplay((string) $c->title),
                'index_state' => $this->indexState($verdicts, $home, (string) $c->slug),
                'inbound_links' => count($graph->inbound((string) $c->id)),
            ])->all();

            $builtCount = count($pills);
            $indexed = count(array_filter($pills, fn (array $p): bool => $p['index_state'] === 'indexed'));
            $status = $this->gate->status($site, $marketId, $tier);

            $bands[] = [
                'tier' => $tierValue ?? 'ungrouped',
                'label' => $tier?->label() ?? 'Ungrouped',
                'served' => $servedInTier,
                'built' => $builtCount,
                'indexed' => $indexed,
                'state' => $this->bandState($builtCount, $indexed, $status->buildable),
                'unlock' => $builtCount === 0 && ! $status->buildable ? $status->reason : null,
                'progress' => $builtCount > 0 ? round($indexed / $builtCount, 2) : 0.0,
                'towns' => $pills,
            ];

            $builtTotal += $builtCount;
            $servedTotal += $servedInTier;
            $problem += $builtCount - $indexed;
        }

        return [
            'id' => $marketId,
            'name' => $this->marketName($location),
            'built' => $builtTotal,
            'served' => $servedTotal,
            'problem_count' => $problem,
            'has_problem' => $problem > 0,
            'tiers' => $bands,
        ];
    }

    private function bandState(int $built, int $indexed, bool $buildable): string
    {
        return match (true) {
            $built > 0 && $indexed >= $built => 'complete',
            $built > 0 => 'indexing',
            $buildable => 'ready',       // unlocked, nothing built yet
            default => 'locked',
        };
    }

    /** @param  array<string, string|null>  $verdicts */
    private function indexState(array $verdicts, string $home, string $slug): string
    {
        $url = UrlNormalizer::url($home.'/'.ltrim($slug, '/'));
        if (! array_key_exists($url, $verdicts)) {
            return 'unknown'; // never inspected
        }

        return match ($verdicts[$url]) {
            'PASS' => 'indexed',
            'FAIL' => 'failed',
            default => 'pending', // NEUTRAL / other verdict
        };
    }

    private function tierValue(CoverageArea $area): ?string
    {
        return is_string($area->size_tier) && $area->size_tier !== '' ? $area->size_tier : null;
    }

    private function marketName(Location $location): string
    {
        ['city' => $city, 'state' => $state] = $location->cityState();
        $city = trim($city) !== '' ? trim($city) : trim((string) $location->name);
        $state = trim($state);

        return $state !== '' && $city !== '' ? "{$city}, {$state}" : ($city !== '' ? $city : 'Location');
    }

    /** Normalize a town name for the tier join (drop a trailing ", ST", lower) — mirrors {@see TierGate}. */
    private function townKey(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', trim($name))));
    }

    /** The display town name (trailing ", ST" dropped, original case) for a pill label. */
    private function townKeyDisplay(string $name): string
    {
        return trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', trim($name)));
    }

    /** @param  Collection<int, CoverageArea>  $coverage @return array<string, string> */
    private function tierByTown(Collection $coverage): array
    {
        $map = [];
        foreach ($coverage as $area) {
            $tier = $this->tierValue($area);
            if ($tier !== null) {
                $map[$this->townKey((string) $area->name)] = $tier;
            }
        }

        return $map;
    }

    /** @return Collection<int, CoverageArea> */
    private function coverage(Site $site): Collection
    {
        return CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get(['id', 'name', 'size_tier', 'source_location_ids']);
    }

    /** @return Collection<int, Content> */
    private function townPages(Site $site): Collection
    {
        return Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->whereNull('location_id')
            ->whereNotNull('parent_location_id')
            ->whereNull('primary_service_id') // exclude city-service pages — town pages only
            ->get(['id', 'title', 'slug', 'parent_location_id']);
    }

    /** @return array<string, string|null> url_normalized => index_verdict */
    private function verdicts(Site $site): array
    {
        $map = [];
        $rows = PageIndexState::query()
            ->where('site_id', $site->id)
            ->get(['url_normalized', 'index_verdict']);
        foreach ($rows as $row) {
            $map[(string) $row->url_normalized] = $row->index_verdict;
        }

        return $map;
    }

    /** @return Collection<int, Location> */
    private function markets(Site $site): Collection
    {
        return Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->get();
    }
}
