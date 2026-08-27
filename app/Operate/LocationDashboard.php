<?php

namespace App\Operate;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\GeoGrid\GeoGridBoard;
use App\Local\Proof\LocalJob;
use App\Local\Proof\LocalJobProvider;
use App\Local\Proof\LocalReview;
use App\Local\Proof\LocalReviewProvider;
use App\Metrics\UrlNormalizer;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Operator\Coverage\PositionTracking;
use App\Support\PublicUrl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The operator per-location dashboard read-model (§ Geo Grid PR 7). One physical, GBP-backed location's whole
 * cluster at a glance: its landing/hub page + the town pages nested under it, and every existing v1 pipeline
 * FILTERED to that cluster — GSC performance, page inventory, indexing, keyword movement, geo grids, reviews,
 * jobs. This is assembly, not new ingest: cluster resolution is the persisted `contents.location_id` (hub) /
 * `contents.parent_location_id` (towns) written at sync time, never recomputed here.
 *
 * Reads the metric SPINE (metric_snapshots) for GSC — never a live provider call — so the whole dashboard is
 * cheap indexed lookups. Operator context crosses tenants, so every query drops {@see SiteScope} and filters
 * on site_id explicitly.
 */
class LocationDashboard
{
    /** GSC performance window (days) — trailing, matching the client dashboard's frame default feel. */
    private const WINDOW_DAYS = 28;

    public function __construct(
        private readonly PositionTracking $tracking,
        private readonly GeoGridBoard $geoGrid,
        private readonly LocalJobProvider $jobs,
        private readonly LocalReviewProvider $reviews,
    ) {}

    /**
     * @return array{
     *   location: array<string, mixed>,
     *   performance: array<string, mixed>,
     *   inventory: array<string, mixed>,
     *   indexing: array<string, mixed>,
     *   keywords: list<array<string, mixed>>,
     *   geo_grid: array<string, mixed>,
     *   reviews: array<string, mixed>,
     *   jobs: array<string, mixed>
     * }
     */
    public function for(Location $location): array
    {
        $site = $location->site;
        $domain = $site?->domain_url;
        $cluster = $this->clusterPages($location);

        return [
            'location' => [
                'id' => (string) $location->id,
                'name' => trim((string) $location->name),
                'address' => $location->address,
                'gbp_backed' => $location->isGbpBacked(),
                'grid_ready' => $location->isGridReady(),
            ],
            'performance' => $this->performance($location->site_id, $domain, $cluster),
            'inventory' => $this->inventory($location, $cluster),
            'indexing' => $this->indexing($location->site_id, $domain, $cluster),
            'keywords' => $this->keywordMovement($location->site_id, $cluster),
            'geo_grid' => $this->geoSummary($location),
            'reviews' => $this->reviewSummary($location),
            'jobs' => $this->jobSummary($location),
        ];
    }

    /**
     * The location's cluster: its hub (landing) page and the town pages nested under it — read straight from
     * the persisted assignment columns.
     *
     * @return array{hub: ?Content, towns: Collection<int, Content>, all: Collection<int, Content>}
     */
    private function clusterPages(Location $location): array
    {
        $hub = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $location->site_id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->where('location_id', $location->id)
            ->first();

        $towns = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $location->site_id)
            ->where('kind', ContentKind::Page->value)
            ->where('page_type', PageType::Location->value)
            ->whereNull('location_id')
            ->whereNull('primary_service_id')
            ->where('parent_location_id', $location->id)
            ->orderBy('title')
            ->get();

        $all = $hub !== null ? $towns->prepend($hub) : $towns;

        return ['hub' => $hub, 'towns' => $towns, 'all' => $all];
    }

    /**
     * Cluster GSC performance from the metric spine — impressions/clicks over the trailing window, totalled
     * and broken out per page. No live provider call.
     *
     * @param  array{hub: ?Content, towns: Collection<int, Content>, all: Collection<int, Content>}  $cluster
     * @return array{window_days: int, impressions: int, clicks: int, pages: list<array{title: string, path: string, impressions: int, clicks: int}>}
     */
    private function performance(string $siteId, ?string $domain, array $cluster): array
    {
        $pathByContent = [];
        foreach ($cluster['all'] as $content) {
            $pathByContent[(string) $content->id] = $this->pathFor($domain, $content);
        }
        $paths = array_values(array_unique($pathByContent));

        $start = now()->subDays(self::WINDOW_DAYS - 1)->toDateString();
        $end = now()->toDateString();

        // dimension_value(page path) => ['impressions'=>n, 'clicks'=>n] summed over the window.
        $rows = $paths === [] ? collect() : DB::table('metric_snapshots')
            ->where('site_id', $siteId)->where('provider', 'gsc')->where('dimension_type', 'page')
            ->whereIn('metric_key', ['impressions', 'clicks'])
            ->whereIn('dimension_value', $paths)
            ->whereBetween('period_date', [$start, $end])
            ->selectRaw('dimension_value, metric_key, SUM(value_numeric) as total')
            ->groupBy('dimension_value', 'metric_key')
            ->get();

        $byPath = [];
        foreach ($rows as $r) {
            $byPath[$r->dimension_value][$r->metric_key] = (int) round((float) $r->total);
        }

        $pages = $cluster['all']->map(function (Content $c) use ($pathByContent, $byPath): array {
            $path = $pathByContent[(string) $c->id];

            return [
                'title' => trim((string) $c->title),
                'path' => $path,
                'impressions' => $byPath[$path]['impressions'] ?? 0,
                'clicks' => $byPath[$path]['clicks'] ?? 0,
            ];
        })->sortByDesc('impressions')->values()->all();

        return [
            'window_days' => self::WINDOW_DAYS,
            'impressions' => array_sum(array_column($pages, 'impressions')),
            'clicks' => array_sum(array_column($pages, 'clicks')),
            'pages' => $pages,
        ];
    }

    /**
     * Cluster inventory — pages live, towns covered, and the population those towns represent (total and the
     * share already backed by a published page).
     *
     * @param  array{hub: ?Content, towns: Collection<int, Content>, all: Collection<int, Content>}  $cluster
     * @return array{pages_live: int, pages_total: int, hub_live: bool, towns_covered: int, towns_selected: int, population_total: int, population_published: int}
     */
    private function inventory(Location $location, array $cluster): array
    {
        $areas = CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $location->site_id)
            ->get()
            ->filter(fn (CoverageArea $a): bool => in_array((string) $location->id, $this->sources($a), true))
            ->values();

        // Town keys that have a PUBLISHED page, to attribute population coverage.
        $publishedTownKeys = $cluster['towns']
            ->filter(fn (Content $c): bool => $c->status === ContentStatus::Published)
            ->map(fn (Content $c): string => $this->townKey((string) $c->title))
            ->unique();

        $populationPublished = $areas
            ->filter(fn (CoverageArea $a): bool => $publishedTownKeys->contains($this->townKey((string) $a->name)))
            ->sum(fn (CoverageArea $a): int => (int) ($a->population ?? 0));

        $live = $cluster['all']->filter(fn (Content $c): bool => $c->status === ContentStatus::Published)->count();

        return [
            'pages_live' => $live,
            'pages_total' => $cluster['all']->count(),
            'hub_live' => $cluster['hub']?->status === ContentStatus::Published,
            'towns_covered' => $areas->count(),
            'towns_selected' => $areas->where('page_selected', true)->count(),
            'population_total' => (int) $areas->sum(fn (CoverageArea $a): int => (int) ($a->population ?? 0)),
            'population_published' => (int) $populationPublished,
        ];
    }

    /**
     * Cluster indexing — indexed vs known URLs, honest union (earned GSC impressions OR a URL-Inspection PASS)
     * so a page indexed with zero impressions still counts and a correct exclusion isn't called "pending".
     *
     * @param  array{hub: ?Content, towns: Collection<int, Content>, all: Collection<int, Content>}  $cluster
     * @return array{known: int, indexed: int, pending: int, pages: list<array{title: string, indexed: bool}>}
     */
    private function indexing(string $siteId, ?string $domain, array $cluster): array
    {
        $published = $cluster['all']->filter(fn (Content $c): bool => $c->status === ContentStatus::Published)->values();

        $impressionPaths = array_flip(DB::table('metric_snapshots')
            ->where('site_id', $siteId)->where('provider', 'gsc')->where('metric_key', 'impressions')
            ->where('dimension_type', 'page')->where('value_numeric', '>', 0)
            ->distinct()->pluck('dimension_value')->all());

        $passUrls = array_flip(DB::table('page_index_states')
            ->where('site_id', $siteId)->where('index_verdict', 'PASS')
            ->pluck('url_normalized')->all());

        $pages = $published->map(function (Content $c) use ($domain, $impressionPaths, $passUrls): array {
            $url = PublicUrl::forContent($domain, $c);
            $path = $this->pathFor($domain, $c);
            $indexed = isset($impressionPaths[$path])
                || ($url !== null && isset($passUrls[UrlNormalizer::url($url)]));

            return ['title' => trim((string) $c->title), 'indexed' => $indexed];
        })->values()->all();

        $indexed = count(array_filter($pages, fn (array $p): bool => $p['indexed']));

        return [
            'known' => count($pages),
            'indexed' => $indexed,
            'pending' => count($pages) - $indexed,
            'pages' => $pages,
        ];
    }

    /**
     * Location-scoped keyword movement — the cluster pages' target keywords with their current organic rank
     * and movement over the tracked series. Keyword→location is via `Content.target_keyword_id`.
     *
     * @param  array{hub: ?Content, towns: Collection<int, Content>, all: Collection<int, Content>}  $cluster
     * @return list<array{keyword: string, page: string, rank: ?int, delta: ?int, url: ?string}>
     */
    private function keywordMovement(string $siteId, array $cluster): array
    {
        $out = [];
        foreach ($cluster['all'] as $content) {
            if ($content->target_keyword_id === null) {
                continue;
            }
            $keyword = Keyword::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $siteId)->whereKey($content->target_keyword_id)->first();
            if ($keyword === null) {
                continue;
            }

            $standings = $this->tracking->forKeyword($keyword);
            $out[] = [
                'keyword' => (string) $keyword->query,
                'page' => trim((string) $content->title),
                'rank' => $standings->organicRank,
                'delta' => $this->seriesDelta($standings->organicSeries),
                'url' => $standings->organicUrl,
            ];
        }

        // Ranked first (best rank), unranked last.
        usort($out, fn (array $a, array $b): int => ($a['rank'] ?? PHP_INT_MAX) <=> ($b['rank'] ?? PHP_INT_MAX));

        return $out;
    }

    /**
     * Geo-grid summary for the location — keyword count scanned, worst/median ATRP, mean SoLV, last scan — a
     * teaser for the full small-multiples board (PR 6) which the surface deep-links to.
     *
     * @return array{available: bool, keyword_count: int, worst_atrp: ?float, best_atrp: ?float, mean_solv: ?float, last_scan: ?string}
     */
    private function geoSummary(Location $location): array
    {
        if (! $location->isGbpBacked()) {
            return ['available' => false, 'keyword_count' => 0, 'worst_atrp' => null, 'best_atrp' => null, 'mean_solv' => null, 'last_scan' => null];
        }

        $board = $this->geoGrid->for($location);
        $cards = collect($board['cards']);
        if ($cards->isEmpty()) {
            return ['available' => true, 'keyword_count' => 0, 'worst_atrp' => null, 'best_atrp' => null, 'mean_solv' => null, 'last_scan' => null];
        }

        $atrps = $cards->pluck('atrp')->filter(fn ($v): bool => $v !== null);
        $solvs = $cards->pluck('solv')->filter(fn ($v): bool => $v !== null);
        $scans = $cards->pluck('scanned_at')->filter();

        return [
            'available' => true,
            'keyword_count' => $cards->count(),
            'worst_atrp' => $atrps->isNotEmpty() ? (float) $atrps->max() : null,
            'best_atrp' => $atrps->isNotEmpty() ? (float) $atrps->min() : null,
            'mean_solv' => $solvs->isNotEmpty() ? round((float) $solvs->avg(), 1) : null,
            'last_scan' => $scans->isNotEmpty() ? (string) $scans->max() : null,
        ];
    }

    /**
     * Reviews filtered to the location — via the (not-yet-deployed) provider seam; empty until a real
     * provider binds, in which case the section notes it's pending rather than faking rows.
     *
     * @return array{available: bool, count: int, average: ?float, items: list<array{author: string, rating: int, text: string, town: string}>}
     */
    private function reviewSummary(Location $location): array
    {
        $reviews = $this->reviews->for($location);
        if ($reviews === []) {
            return ['available' => false, 'count' => 0, 'average' => null, 'items' => []];
        }

        $items = array_map(fn (LocalReview $r): array => [
            'author' => $r->authorFirst, 'rating' => $r->rating, 'text' => $r->text, 'town' => $r->town,
        ], $reviews);

        return [
            'available' => true,
            'count' => count($items),
            'average' => round(array_sum(array_column($items, 'rating')) / count($items), 1),
            'items' => array_slice($items, 0, 6),
        ];
    }

    /**
     * Job-capture proof within the location's radius — reuses the live provider directly.
     *
     * @return array{count: int, items: list<array{title: string, town: string, service: ?string, date: ?string}>}
     */
    private function jobSummary(Location $location): array
    {
        $jobs = $this->jobs->for($location);

        return [
            'count' => count($jobs),
            'items' => array_map(fn (LocalJob $j): array => [
                'title' => $j->title, 'town' => $j->town, 'service' => $j->service, 'date' => $j->date,
            ], $jobs),
        ];
    }

    /** Normalized page path for a content (canonical public URL, else the bare slug). */
    private function pathFor(?string $domain, Content $content): string
    {
        $url = PublicUrl::forContent($domain, $content);

        return UrlNormalizer::path($url ?? '/'.ltrim((string) $content->slug, '/'));
    }

    /** Movement across a rank series: earliest − latest (positive = improved, lower rank is better). */
    private function seriesDelta(array $series): ?int
    {
        $points = collect($series)
            ->filter(fn (array $p): bool => ($p['rank'] ?? null) !== null)
            ->sortBy('captured_at')
            ->values();
        if ($points->count() < 2) {
            return null;
        }

        return (int) $points->first()['rank'] - (int) $points->last()['rank'];
    }

    /** Normalize a town name for matching a coverage row to its page (drop a trailing ", ST", lower). */
    private function townKey(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/,\s*[A-Za-z]{2}\.?$/', '', trim($name))));
    }

    /**
     * @return list<string>
     */
    private function sources(CoverageArea $area): array
    {
        return is_array($area->source_location_ids)
            ? array_map('strval', $area->source_location_ids)
            : [];
    }
}
