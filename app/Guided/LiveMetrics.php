<?php

namespace App\Guided;

use App\Integrations\Analytics\PageTrafficProvider;
use App\Integrations\BingWebmaster\BingWebmasterProvider;
use App\Integrations\SearchConsole\PageQuery;
use App\Integrations\SearchConsole\SearchConsoleProvider;
use App\Integrations\UrlInspection\IndexInspector;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\PositionSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Operator\Coverage\PositionTracking;
use Illuminate\Support\Carbon;

/**
 * One published page's tracking block for the Live boards — position (the §5 snapshot series via
 * the §7b standings reader), Search Console, and GA4 traffic through their vendor-deferred seams.
 * Every cell is HONEST: a metric renders only when its source is connected and has data; the
 * alternative is a specific pending reason ("first snapshot pending", "connect GA4"), never a
 * fabricated zero. Refresh markers ride the series as observed correlation (§7c framing).
 */
class LiveMetrics
{
    public function __construct(
        private readonly PositionTracking $tracking,
        private readonly SearchConsoleProvider $searchConsole,
        private readonly PageTrafficProvider $traffic,
        private readonly BingWebmasterProvider $bing,
        private readonly IndexInspector $indexInspector,
    ) {}

    /**
     * Which sources are connected for the site — drives the boards' source chips once per render.
     *
     * @return array{serp: bool, gsc: bool, ga: bool, bing: bool}
     */
    public function sources(Site $site): array
    {
        return [
            // SERP "connected" = real snapshots exist for the tenant (the DataForSEO adapter is a
            // later relay; staged/mock-fed snapshots are still real rows and render honestly).
            'serp' => PositionSnapshot::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->exists(),
            'gsc' => $this->searchConsole->connected($site),
            'ga' => $this->traffic->connected($site),
            'bing' => $this->bing->connected($site),
        ];
    }

    /**
     * The tracking block for one published page.
     *
     * @return array{
     *   keyword: ?string,
     *   position: array{rank: ?int, delta: ?int, pending: ?string},
     *   local: array{rank: ?int, market: ?string},
     *   series: list<array{captured_at: string, rank: ?int}>,
     *   refresh_count: int,
     *   gsc: array{impressions: ?int, clicks: ?int, ctr: ?float, in_google: bool, queries: list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}>, pending: ?string},
     *   index: array{state: ?string, label: ?string, indexed: bool, coverage_state: ?string, canonical_mismatch: bool, pending: ?string},
     *   bing: array{impressions: ?int, clicks: ?int, ctr: ?float, in_bing: bool, queries: list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}>, pending: ?string},
     *   traffic: array{sessions: ?int, pending: ?string}
     * }
     */
    public function for(Content $page): array
    {
        $site = $page->site;
        $keyword = $page->target_keyword_id !== null
            ? Keyword::withoutGlobalScope(SiteScope::class)->find($page->target_keyword_id)
            : null;

        [$position, $local, $series, $refreshCount] = $this->positionBlock($page, $keyword);

        return [
            'keyword' => $keyword?->query,
            'position' => $position,
            'local' => $local,
            'series' => $series,
            'refresh_count' => $refreshCount,
            'gsc' => $this->gscBlock($site, $page),
            'index' => $this->indexBlock($site, $page),
            'bing' => $this->bingBlock($site, $page),
            'traffic' => $this->trafficBlock($site, $page),
        ];
    }

    /**
     * The AUTHORITATIVE index-coverage block — the real Google URL Inspection `coverageState`, distinct
     * from the impressions>0 `in_google` proxy. CACHE-ONLY on render (never a live API call — URL
     * Inspection is quota-limited): it lights up once `launchpad:audit-index` has inspected the URL, else
     * an honest pending. A `redirect`/`canonical` exclusion is a real, correct state, not a fabricated zero.
     *
     * @return array{state: ?string, label: ?string, indexed: bool, coverage_state: ?string, canonical_mismatch: bool, pending: ?string}
     */
    private function indexBlock(?Site $site, Content $page): array
    {
        $blank = ['state' => null, 'label' => null, 'indexed' => false, 'coverage_state' => null, 'canonical_mismatch' => false];

        if ($site === null || ! $this->indexInspector->connected($site)) {
            return $blank + ['pending' => 'Connect Search Console'];
        }

        $home = rtrim((string) $site->domain_url, '/');
        $status = $home !== '' ? $this->indexInspector->cached($site, $home.'/'.ltrim((string) $page->slug, '/')) : null;
        if ($status === null) {
            return $blank + ['pending' => 'Run index audit'];
        }

        return [
            'state' => $status->state->value,
            'label' => $status->state->label(),
            'indexed' => $status->indexed(),
            'coverage_state' => $status->coverageState,
            'canonical_mismatch' => $status->canonicalMismatch(),
            'pending' => null,
        ];
    }

    /**
     * @return array{0: array{rank: ?int, delta: ?int, pending: ?string}, 1: array{rank: ?int, market: ?string}, 2: list<array{captured_at: string, rank: ?int}>, 3: int}
     */
    private function positionBlock(Content $page, ?Keyword $keyword): array
    {
        if ($keyword === null) {
            // Core/brand pages aren't keyword-targeted — say why, don't show a fake dash.
            return [['rank' => null, 'delta' => null, 'pending' => 'No target keyword — brand page'], ['rank' => null, 'market' => null], [], 0];
        }

        $standings = $this->tracking->forKeyword($keyword);

        if ($standings->organicRank === null && $standings->localByMarket === []) {
            return [['rank' => null, 'delta' => null, 'pending' => 'First snapshot pending'], ['rank' => null, 'market' => null], [], $standings->refreshCount];
        }

        // Best (lowest-rank) local-pack standing across markets, for the local chip.
        $bestLocal = collect($standings->localByMarket)
            ->filter(fn (array $l) => $l['rank'] !== null)
            ->sortBy('rank')
            ->first();

        return [
            [
                'rank' => $standings->organicRank,
                'delta' => $this->delta($standings->organicSeries),
                'pending' => $standings->organicRank === null ? 'No organic capture yet' : null,
            ],
            [
                'rank' => $bestLocal['rank'] ?? null,
                'market' => $bestLocal['market_name'] ?? null,
            ],
            $standings->organicSeries,
            $standings->refreshCount,
        ];
    }

    /**
     * Rank movement vs ~30 days ago: positive = improved (rank number went DOWN). Null without a
     * comparison point — a young series shows no arrow rather than a made-up one.
     *
     * @param  list<array{captured_at: string, rank: ?int}>  $series  newest-last or newest-first; handled either way
     */
    private function delta(array $series): ?int
    {
        $points = collect($series)
            ->filter(fn (array $p) => $p['rank'] !== null)
            ->sortBy('captured_at')
            ->values();
        if ($points->count() < 2) {
            return null;
        }

        $latest = $points->last();
        $cutoff = Carbon::parse((string) $latest['captured_at'])->subDays(30);

        // The oldest point INSIDE the window (closest to 30d back); fall back to the series start.
        $then = $points->first(fn (array $p) => Carbon::parse((string) $p['captured_at'])->gte($cutoff)) ?? $points->first();
        if ($then === $latest) {
            $then = $points->slice(-2, 1)->first();
        }

        return (int) $then['rank'] - (int) $latest['rank'];
    }

    /**
     * @return array{impressions: ?int, clicks: ?int, ctr: ?float, in_google: bool, queries: list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}>, pending: ?string}
     */
    private function gscBlock(?Site $site, Content $page): array
    {
        if ($site === null || ! $this->searchConsole->connected($site)) {
            return ['impressions' => null, 'clicks' => null, 'ctr' => null, 'in_google' => false, 'queries' => [], 'pending' => 'Connect Search Console'];
        }

        $path = '/'.ltrim((string) $page->slug, '/');
        $stats = $this->searchConsole->pageStats($site, $path);
        if ($stats === null) {
            return ['impressions' => null, 'clicks' => null, 'ctr' => null, 'in_google' => false, 'queries' => [], 'pending' => 'Collecting — first data in a few days'];
        }

        // The long tail this page is actually found for (free GSC signal — every "sump pump {city}" /
        // "near me" variant). Location pages own geo, which silo keyword tracking excludes by design.
        $queries = array_map(fn (PageQuery $q): array => [
            'query' => $q->query, 'clicks' => $q->clicks, 'impressions' => $q->impressions, 'ctr' => $q->ctr, 'position' => $q->position,
        ], $this->searchConsole->pageQueries($site, $path));

        // "In Google" = the page has earned Search impressions, so it is definitely indexed and
        // appearing. (A page indexed with zero impressions simply won't light up yet — we never claim
        // "not indexed", only the positive.)
        return ['impressions' => $stats->impressions, 'clicks' => $stats->clicks, 'ctr' => $stats->ctr(), 'in_google' => $stats->impressions > 0, 'queries' => $queries, 'pending' => null];
    }

    /**
     * The Bing Webmaster Tools block — the Bing twin of {@see gscBlock}. `in_bing` = earned Bing
     * impressions (the same positive-only rule as "In Google"), which upgrades the card's blue
     * "Submitted to Bing" pill to a green "In Bing". Disconnected or no-data-yet → the honest pending
     * reason, never a fabricated zero. Only reached when a BWT key + bing_site_url are configured;
     * otherwise the Null adapter reports not-connected and the pill stays "Submitted".
     *
     * @return array{impressions: ?int, clicks: ?int, ctr: ?float, in_bing: bool, queries: list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}>, pending: ?string}
     */
    private function bingBlock(?Site $site, Content $page): array
    {
        if ($site === null || ! $this->bing->connected($site)) {
            return ['impressions' => null, 'clicks' => null, 'ctr' => null, 'in_bing' => false, 'queries' => [], 'pending' => 'Connect Bing Webmaster'];
        }

        $path = '/'.ltrim((string) $page->slug, '/');
        $stats = $this->bing->pageStats($site, $path);
        if ($stats === null) {
            return ['impressions' => null, 'clicks' => null, 'ctr' => null, 'in_bing' => false, 'queries' => [], 'pending' => 'Collecting — first Bing data in a few days'];
        }

        $queries = array_map(fn (PageQuery $q): array => [
            'query' => $q->query, 'clicks' => $q->clicks, 'impressions' => $q->impressions, 'ctr' => $q->ctr, 'position' => $q->position,
        ], $this->bing->pageQueries($site, $path));

        return ['impressions' => $stats->impressions, 'clicks' => $stats->clicks, 'ctr' => $stats->ctr(), 'in_bing' => $stats->impressions > 0, 'queries' => $queries, 'pending' => null];
    }

    /**
     * @return array{sessions: ?int, pending: ?string}
     */
    private function trafficBlock(?Site $site, Content $page): array
    {
        if ($site === null || ! $this->traffic->connected($site)) {
            return ['sessions' => null, 'pending' => 'Connect GA4'];
        }

        $sessions = $this->traffic->sessions($site, '/'.ltrim((string) $page->slug, '/'));
        if ($sessions === null) {
            return ['sessions' => null, 'pending' => 'Collecting'];
        }

        return ['sessions' => $sessions, 'pending' => null];
    }
}
