<?php

namespace App\Guided;

use App\Enums\RankingState;
use App\Enums\SerpTaskState;
use App\Integrations\Analytics\PageTrafficProvider;
use App\Integrations\BingWebmaster\BingWebmasterProvider;
use App\Integrations\SearchConsole\PageQuery;
use App\Integrations\SearchConsole\SearchConsoleProvider;
use App\Integrations\UrlInspection\IndexInspector;
use App\Jobs\WarmGa4Pages;
use App\Jobs\WarmLiveMetrics;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\PositionSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\SerpTask;
use App\Models\Site;
use App\Operator\Coverage\PositionTracking;
use App\Support\PublicUrl;
use Illuminate\Support\Carbon;
use Traversable;

/**
 * One published page's tracking block for the Live boards — position (the §5 snapshot series via
 * the §7b standings reader), Search Console, and GA4 traffic through their vendor-deferred seams.
 * Every cell is HONEST: a metric renders only when its source is connected and has data; the
 * alternative is a specific pending reason ("first snapshot pending", "connect GA4"), never a
 * fabricated zero. Refresh markers ride the series as observed correlation (§7c framing).
 */
class LiveMetrics
{
    /**
     * Search Console figures for the pages of the board being built, primed in one pass.
     *
     * @var array<string, array{totals: array<string, mixed>|null, queries: list<array<string, mixed>>}>
     */
    private array $primed = [];

    /**
     * Prime from OUR OWN tables for a set of pages, so the cards stop asking the vendor client one page at
     * a time for data the daily sync already stored. Two grouped queries, whatever the card count.
     *
     * @param  iterable<Content>  $pages
     */
    public function prime(Site $site, iterable $pages): void
    {
        $pages = $pages instanceof Traversable ? iterator_to_array($pages) : $pages;
        $totals = $this->stored->totals($site, $pages);
        $queries = $this->stored->queries($site, $pages);

        foreach ($pages as $page) {
            $id = (string) $page->id;
            $this->primed[$id] = ['totals' => $totals[$id] ?? null, 'queries' => $queries[$id] ?? []];
        }

        // The target keywords in one query too — the position block looked each one up by id, per card.
        $keywordIds = collect($pages)->pluck('target_keyword_id')->filter()->unique()->values()->all();
        if ($keywordIds !== []) {
            $this->keywords = Keyword::withoutGlobalScope(SiteScope::class)->whereKey($keywordIds)->get()
                ->keyBy(fn (Keyword $k): string => (string) $k->id)
                ->all();
        }
    }

    /**
     * Target keywords for the primed pages, loaded once.
     *
     * @var array<string, Keyword>
     */
    private array $keywords = [];

    public function __construct(
        private readonly PositionTracking $tracking,
        private readonly SearchConsoleProvider $searchConsole,
        private readonly PageTrafficProvider $traffic,
        private readonly BingWebmasterProvider $bing,
        private readonly IndexInspector $indexInspector,
        private readonly StoredSearchMetrics $stored,
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
     *   position: array{rank: ?int, delta: ?int, pending: ?string, state: ?string},
     *   local: array{rank: ?int, market: ?string},
     *   series: list<array{captured_at: string, rank: ?int}>,
     *   refresh_count: int,
     *   gsc: array{impressions: ?int, clicks: ?int, ctr: ?float, position: ?float, in_google: bool, queries: list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}>, pending: ?string},
     *   index: array{state: ?string, label: ?string, indexed: bool, coverage_state: ?string, canonical_mismatch: bool, last_crawled_at: ?string, pending: ?string},
     *   bing: array{impressions: ?int, clicks: ?int, ctr: ?float, in_bing: bool, queries: list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}>, pending: ?string},
     *   traffic: array{sessions: ?int, pending: ?string}
     * }
     */
    /**
     * @param  bool  $liveTraffic  false = read GA4 sessions from the warmed cache only (never a live GA4
     *                             call), for a render path. GA4 is fetched off-request by the weekly
     *                             {@see WarmGa4Pages}; the other blocks are unaffected. True
     *                             (the default) keeps the legacy live fetch for any non-render caller.
     * @param  bool  $liveSearch  false = the same rule for Search Console and Bing: cache-only, so a cold
     *                            page costs nothing on render. These two fetch on a MISS by default, which
     *                            put one Google round trip per uncached page inside the request — a board of
     *                            fifty town pages then spent the whole gateway budget before rendering a
     *                            row. {@see WarmLiveMetrics} fills these caches off-request.
     */
    public function for(Content $page, bool $defer = false, bool $liveTraffic = true, bool $liveSearch = true): array
    {
        // Over the board's live-metrics budget (or an off-screen card): return a fully-shaped, zero-cost
        // "refreshing" block — no DB, no external call. The WarmLiveMetrics worker fills the real values
        // into cache off-request, so the next load shows them. Honest pending, never a fabricated number.
        if ($defer) {
            return $this->deferred();
        }

        $site = $page->site;
        $keyword = $page->target_keyword_id !== null
            ? ($this->keywords[(string) $page->target_keyword_id] ?? Keyword::withoutGlobalScope(SiteScope::class)->find($page->target_keyword_id))
            : null;

        [$position, $local, $series, $refreshCount] = $this->positionBlock($page, $keyword);

        return [
            'keyword' => $keyword?->query,
            'position' => $position,
            'local' => $local,
            'series' => $series,
            'refresh_count' => $refreshCount,
            'gsc' => $this->gscBlock($site, $page, $liveSearch),
            'index' => $this->indexBlock($site, $page),
            'bing' => $this->bingBlock($site, $page, $liveSearch),
            'traffic' => $this->trafficBlock($site, $page, $liveTraffic),
        ];
    }

    /**
     * A fully-shaped, zero-cost metrics block for a deferred render — matches {@see for()}'s contract
     * exactly but touches no DB or vendor API. Every cell reads "Refreshing…" so the card renders
     * instantly while {@see WarmLiveMetrics} warms the real values into cache.
     *
     * @return array{
     *   keyword: ?string,
     *   position: array{rank: ?int, delta: ?int, pending: ?string, state: ?string},
     *   local: array{rank: ?int, market: ?string},
     *   series: list<array{captured_at: string, rank: ?int}>,
     *   refresh_count: int,
     *   gsc: array{impressions: ?int, clicks: ?int, ctr: ?float, position: ?float, in_google: bool, queries: list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}>, pending: ?string},
     *   index: array{state: ?string, label: ?string, indexed: bool, coverage_state: ?string, canonical_mismatch: bool, last_crawled_at: ?string, pending: ?string},
     *   bing: array{impressions: ?int, clicks: ?int, ctr: ?float, in_bing: bool, queries: list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}>, pending: ?string},
     *   traffic: array{sessions: ?int, pending: ?string}
     * }
     */
    private function deferred(): array
    {
        $refreshing = 'Refreshing…';

        return [
            'keyword' => null,
            'position' => ['rank' => null, 'delta' => null, 'pending' => $refreshing, 'state' => null],
            'local' => ['rank' => null, 'market' => null],
            'series' => [],
            'refresh_count' => 0,
            'gsc' => ['impressions' => null, 'clicks' => null, 'ctr' => null, 'position' => null, 'in_google' => false, 'queries' => [], 'pending' => $refreshing],
            'index' => ['state' => null, 'label' => null, 'indexed' => false, 'coverage_state' => null, 'canonical_mismatch' => false, 'last_crawled_at' => null, 'pending' => $refreshing],
            'bing' => ['impressions' => null, 'clicks' => null, 'ctr' => null, 'in_bing' => false, 'queries' => [], 'pending' => $refreshing],
            'traffic' => ['sessions' => null, 'pending' => $refreshing],
        ];
    }

    /**
     * The GSC block from the primed store. No rows for a page is the honest "collecting" — the sync writes
     * a row the day a page earns its first impression.
     *
     * @return array{impressions: ?int, clicks: ?int, ctr: ?float, position: ?float, in_google: bool, queries: list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}>, pending: ?string}
     */
    private function primedGsc(string $pageId): array
    {
        $totals = $this->primed[$pageId]['totals'] ?? null;
        if ($totals === null) {
            return ['impressions' => null, 'clicks' => null, 'ctr' => null, 'position' => null, 'in_google' => false, 'queries' => [],
                'pending' => 'Collecting — first data in a few days'];
        }

        $queries = array_map(fn (array $q): array => [
            'query' => $q['query'],
            'clicks' => $q['clicks'],
            'impressions' => $q['impressions'],
            'ctr' => $q['impressions'] > 0 ? round($q['clicks'] / $q['impressions'], 4) : 0.0,
            'position' => (float) ($q['position'] ?? 0),
        ], $this->primed[$pageId]['queries'] ?? []);

        return [
            'impressions' => (int) $totals['impressions'],
            'clicks' => (int) $totals['clicks'],
            'ctr' => (float) $totals['ctr'],
            // Google's own blended rank for this page: the average position across every query it was
            // seen for, weighted by how often each was seen. Not the same thing as a tracked rank for one
            // keyword, and labelled separately on the card for exactly that reason.
            'position' => $totals['position'] ?? null,
            // "In Google" = the page has earned impressions, so it is indexed and appearing.
            'in_google' => (int) $totals['impressions'] > 0,
            'queries' => $queries,
            'pending' => null,
        ];
    }

    /**
     * The AUTHORITATIVE index-coverage block — the real Google URL Inspection `coverageState`, distinct
     * from the impressions>0 `in_google` proxy. CACHE-ONLY on render (never a live API call — URL
     * Inspection is quota-limited): it lights up once `launchpad:audit-index` has inspected the URL, else
     * an honest pending. A `redirect`/`canonical` exclusion is a real, correct state, not a fabricated zero.
     *
     * @return array{state: ?string, label: ?string, indexed: bool, coverage_state: ?string, canonical_mismatch: bool, last_crawled_at: ?string, pending: ?string}
     */
    private function indexBlock(?Site $site, Content $page): array
    {
        $blank = ['state' => null, 'label' => null, 'indexed' => false, 'coverage_state' => null, 'canonical_mismatch' => false, 'last_crawled_at' => null];

        if ($site === null || ! $this->indexInspector->connected($site)) {
            return $blank + ['pending' => 'Connect Search Console'];
        }

        // Inspect the trailing-slash (canonical/permalink) form so GSC reports the FINAL URL as Indexed,
        // not the slash-less variant that 301-redirects to it ("Excluded (redirect)").
        $url = PublicUrl::forContent($site->domain_url, $page);
        $status = $url !== null ? $this->indexInspector->cached($site, $url) : null;
        if ($status === null) {
            return $blank + ['pending' => 'Run index audit'];
        }

        return [
            'state' => $status->state->value,
            'label' => $status->state->label(),
            'indexed' => $status->indexed(),
            'coverage_state' => $status->coverageState,
            'canonical_mismatch' => $status->canonicalMismatch(),
            // Google's last-crawl timestamp for the URL — the honest "as of" date for the index verdict.
            'last_crawled_at' => $status->lastCrawledAt?->toDateString(),
            'pending' => null,
        ];
    }

    /**
     * @return array{0: array{rank: ?int, delta: ?int, pending: ?string}, 1: array{rank: ?int, market: ?string}, 2: list<array{captured_at: string, rank: ?int}>, 3: int}
     */
    private function positionBlock(Content $page, ?Keyword $keyword): array
    {
        if ($keyword === null) {
            // Core/brand pages aren't keyword-targeted — there's nothing to rank for → not_tracked (the
            // action is to add coverage, not to improve a page), never a fake dash or a "not ranking".
            return [
                ['rank' => null, 'delta' => null, 'pending' => RankingState::NotTracked->label(), 'state' => RankingState::NotTracked->value],
                ['rank' => null, 'market' => null], [], 0,
            ];
        }

        $standings = $this->tracking->forKeyword($keyword);

        if ($standings->organicRank === null && $standings->localByMarket === []) {
            // The four-state ranking vocabulary, not one catch-all string: tracked_not_ranking (we asked,
            // the SERP returned, we weren't in it) vs checking (a task was dispatched, no snapshot yet).
            $state = $this->organicState($keyword, null);

            return [
                ['rank' => null, 'delta' => null, 'pending' => $state->label(), 'state' => $state->value],
                ['rank' => null, 'market' => null], [], $standings->refreshCount,
            ];
        }

        // Best (lowest-rank) local-pack standing across markets, for the local chip.
        $bestLocal = collect($standings->localByMarket)
            ->filter(fn (array $l) => $l['rank'] !== null)
            ->sortBy('rank')
            ->first();

        $organicState = $this->organicState($keyword, $standings->organicRank);

        return [
            [
                'rank' => $standings->organicRank,
                'delta' => $this->delta($standings->organicSeries),
                'pending' => $organicState === RankingState::Ranked ? null : $organicState->label(),
                'state' => $organicState->value,
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
     * The organic ranking state for the cell: ranked (a position), tracked_not_ranking (a completed SERP
     * pull returned without us — competing and losing), or checking (a task dispatched, no snapshot yet).
     * The not_tracked case (no keyword at all) is handled by the caller.
     */
    private function organicState(Keyword $keyword, ?int $rank): RankingState
    {
        if ($rank !== null) {
            return RankingState::Ranked;
        }

        return $this->pulledUnranked($keyword) ? RankingState::TrackedNotRanking : RankingState::Checking;
    }

    /**
     * Has an organic SERP for this keyword actually been fetched (a completed, ingested standard-mode
     * task) while no snapshot exists? Then the site was looked up and simply isn't in the tracked
     * results yet — tracked_not_ranking — as opposed to no pull having landed at all. Matches on the
     * shared query (the cache/task key is per query × locale, not per tenant), so any tenant's
     * completed pull that this site was scored against counts.
     */
    private function pulledUnranked(Keyword $keyword): bool
    {
        return SerpTask::query()
            ->where('function', 'organic')
            ->where('query', $keyword->query)
            ->where('state', SerpTaskState::Ingested->value)
            ->exists();
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
     * @return array{impressions: ?int, clicks: ?int, ctr: ?float, position: ?float, in_google: bool, queries: list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}>, pending: ?string}
     */
    private function gscBlock(?Site $site, Content $page, bool $live = true): array
    {
        // Primed for this board: our own stored rollups, loaded for every card in one query. Stored rows
        // are data whatever the client's current connection state — the sync that wrote them was connected.
        if ($site !== null && ($this->primed[(string) $page->id]['totals'] ?? null) !== null) {
            return $this->primedGsc((string) $page->id);
        }

        if ($site === null || ! $this->searchConsole->connected($site)) {
            return ['impressions' => null, 'clicks' => null, 'ctr' => null, 'position' => null, 'in_google' => false, 'queries' => [], 'pending' => 'Connect Search Console'];
        }

        // Primed but empty: connected and simply has nothing for this page yet.
        if (array_key_exists((string) $page->id, $this->primed)) {
            return $this->primedGsc((string) $page->id);
        }

        $path = '/'.ltrim((string) $page->slug, '/');
        $stats = $live ? $this->searchConsole->pageStats($site, $path) : $this->searchConsole->pageStatsCached($site, $path);
        if ($stats === null) {
            return ['impressions' => null, 'clicks' => null, 'ctr' => null, 'position' => null, 'in_google' => false, 'queries' => [],
                'pending' => $live ? 'Collecting — first data in a few days' : 'Refreshing…'];
        }

        // The long tail this page is actually found for (free GSC signal — every "sump pump {city}" /
        // "near me" variant). Location pages own geo, which silo keyword tracking excludes by design.
        $queries = array_map(fn (PageQuery $q): array => [
            'query' => $q->query, 'clicks' => $q->clicks, 'impressions' => $q->impressions, 'ctr' => $q->ctr, 'position' => $q->position,
        ], $live ? $this->searchConsole->pageQueries($site, $path) : $this->searchConsole->pageQueriesCached($site, $path));

        // "In Google" = the page has earned Search impressions, so it is definitely indexed and
        // appearing. (A page indexed with zero impressions simply won't light up yet — we never claim
        // "not indexed", only the positive.)
        // PageStats carries no position, but each query does — so the page's blended position is the same
        // impression-weighted average the stored rollup computes, derived here rather than left null.
        $seen = array_sum(array_column($queries, 'impressions'));
        $weighted = 0.0;
        foreach ($queries as $q) {
            $weighted += (float) $q['position'] * (int) $q['impressions'];
        }

        return ['impressions' => $stats->impressions, 'clicks' => $stats->clicks, 'ctr' => $stats->ctr(),
            'position' => $seen > 0 ? round($weighted / $seen, 1) : null,
            'in_google' => $stats->impressions > 0, 'queries' => $queries, 'pending' => null];
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
    private function bingBlock(?Site $site, Content $page, bool $live = true): array
    {
        if ($site === null || ! $this->bing->connected($site)) {
            return ['impressions' => null, 'clicks' => null, 'ctr' => null, 'in_bing' => false, 'queries' => [], 'pending' => 'Connect Bing Webmaster'];
        }

        $path = '/'.ltrim((string) $page->slug, '/');
        $stats = $live ? $this->bing->pageStats($site, $path) : $this->bing->pageStatsCached($site, $path);
        if ($stats === null) {
            return ['impressions' => null, 'clicks' => null, 'ctr' => null, 'in_bing' => false, 'queries' => [],
                'pending' => $live ? 'Collecting — first Bing data in a few days' : 'Refreshing…'];
        }

        $queries = array_map(fn (PageQuery $q): array => [
            'query' => $q->query, 'clicks' => $q->clicks, 'impressions' => $q->impressions, 'ctr' => $q->ctr, 'position' => $q->position,
        ], $live ? $this->bing->pageQueries($site, $path) : $this->bing->pageQueriesCached($site, $path));

        return ['impressions' => $stats->impressions, 'clicks' => $stats->clicks, 'ctr' => $stats->ctr(), 'in_bing' => $stats->impressions > 0, 'queries' => $queries, 'pending' => null];
    }

    /**
     * @param  bool  $live  false = read the warmed GA4 cache only (render path — zero outbound HTTP), where
     *                      a page that's warmed-but-empty reads "No traffic yet" and only a genuine miss
     *                      reads "Refreshing…" while {@see WarmGa4Pages} warms it weekly off-request. True
     *                      fetches live (non-render callers only).
     * @return array{sessions: ?int, pending: ?string}
     */
    private function trafficBlock(?Site $site, Content $page, bool $live = true): array
    {
        if ($site === null || ! $this->traffic->connected($site)) {
            return ['sessions' => null, 'pending' => 'Connect GA4'];
        }

        $path = '/'.ltrim((string) $page->slug, '/');

        if ($live) {
            $sessions = $this->traffic->sessions($site, $path);

            return ['sessions' => $sessions, 'pending' => $sessions === null ? 'Collecting' : null];
        }

        // Cache-only render: a warmed-but-empty page is a real "no traffic yet", NOT the same as a page the
        // weekly warm hasn't reached — only the latter is "Refreshing…" (a value that never resolves is
        // indistinguishable from a broken metric; give each an honest terminal state).
        $state = $this->traffic->sessionsCachedState($site, $path);
        if ($state['sessions'] !== null) {
            return ['sessions' => $state['sessions'], 'pending' => null];
        }

        return ['sessions' => null, 'pending' => $state['warmed'] ? 'No traffic yet' : 'Refreshing…'];
    }
}
