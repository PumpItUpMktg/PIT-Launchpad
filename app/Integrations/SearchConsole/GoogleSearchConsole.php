<?php

namespace App\Integrations\SearchConsole;

use App\Integrations\Google\GoogleConnectionService;
use App\Integrations\Google\GoogleException;
use App\Models\GoogleAccount;
use App\Models\Site;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;

/**
 * The card-facing Search Console adapter — bridges the shared platform Google grant onto the Live
 * boards' {@see SearchConsoleProvider} contract (`connected` / `pageStats`). A tenant is "connected"
 * when the one grant is live AND this Site has a GSC property picked ({@see GoogleAccount}
 * + Site.gsc_property, both from PR-A); a card then shows real impressions/clicks/CTR, and otherwise
 * the honest "Connect Search Console" / "collecting" prompt — never a fabricated zero.
 *
 * `pageStats` runs ONE aggregated Search Console query per page (a page-equals filter, no dimensions →
 * the totals row), cached per (property × path × window) so a board render doesn't fan out into a GSC
 * call per card on every load. GSC data lags ~2-3 days, so a short-lived cache costs nothing in
 * freshness. A transient API error or a page with no data yet resolves to null (the "collecting"
 * cell), never an exception into the board.
 */
class GoogleSearchConsole implements SearchConsoleProvider
{
    public function __construct(
        private readonly GoogleConnectionService $connections,
        private readonly CacheRepository $cache,
        private readonly string $baseUrl,
        private readonly int $cacheTtl = 21600,
    ) {}

    public function connected(Site $site): bool
    {
        $account = $this->connections->account();

        return $account !== null
            && ! $account->needsReconnect()
            && is_string($site->gsc_property)
            && $site->gsc_property !== '';
    }

    public function pageStats(Site $site, string $path, int $days = 28): ?PageSearchStats
    {
        if (! $this->connected($site)) {
            return null;
        }

        $pageUrl = $this->pageUrl($site, $path);
        if ($pageUrl === null) {
            return null; // no site domain to match GSC's page key against
        }

        $property = (string) $site->gsc_property;
        $key = 'gsc:page:'.md5($property.'|'.$pageUrl.'|'.$days);

        // Cache the totals (and the no-data sentinel) so repeated card renders don't re-hit GSC.
        $totals = $this->cache->remember($key, $this->cacheTtl, fn (): array => $this->fetchTotals($property, $pageUrl, $days) ?? ['none' => true]);

        if (isset($totals['none'])) {
            return null;
        }

        return new PageSearchStats(
            impressions: (int) $totals['impressions'],
            clicks: (int) $totals['clicks'],
            days: $days,
        );
    }

    /**
     * @return list<PageQuery>
     */
    public function pageQueries(Site $site, string $path, int $days = 28, int $limit = 8): array
    {
        if (! $this->connected($site)) {
            return [];
        }

        $pageUrl = $this->pageUrl($site, $path);
        if ($pageUrl === null) {
            return [];
        }

        $property = (string) $site->gsc_property;
        $key = 'gsc:pagequeries:'.md5($property.'|'.$pageUrl.'|'.$days.'|'.$limit);

        /** @var list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}> $rows */
        $rows = $this->cache->remember($key, $this->cacheTtl, fn (): array => $this->fetchQueries($property, $pageUrl, $days, $limit));

        return array_map(
            fn (array $r): PageQuery => new PageQuery($r['query'], $r['clicks'], $r['impressions'], $r['ctr'], $r['position']),
            $rows,
        );
    }

    /**
     * The page's top queries over the window (page-equals filter + the `query` dimension), ordered by
     * impressions, capped to $limit. Empty list on a transient API error or a page with no data yet
     * (cached either way, so a board render doesn't re-hit GSC).
     *
     * @return list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}>
     */
    private function fetchQueries(string $property, string $pageUrl, int $days, int $limit): array
    {
        $account = $this->connections->account();
        if ($account === null) {
            return [];
        }

        try {
            $json = $this->connections->request(
                $account,
                'post',
                rtrim($this->baseUrl, '/').'/sites/'.rawurlencode($property).'/searchAnalytics/query',
                ['json' => [
                    'startDate' => Carbon::now()->subDays($days)->format('Y-m-d'),
                    'endDate' => Carbon::now()->format('Y-m-d'),
                    'dimensions' => ['query'],
                    'dimensionFilterGroups' => [[
                        'filters' => [[
                            'dimension' => 'page',
                            'operator' => 'equals',
                            'expression' => $pageUrl,
                        ]],
                    ]],
                    'rowLimit' => max(1, $limit),
                ]],
            );
        } catch (GoogleException) {
            return [];
        }

        $out = [];
        foreach ((array) ($json['rows'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $query = (string) (($row['keys'][0] ?? '') ?: '');
            if ($query === '') {
                continue;
            }
            $out[] = [
                'query' => $query,
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => round((float) ($row['ctr'] ?? 0) * 100, 1),
                'position' => round((float) ($row['position'] ?? 0), 1),
            ];
        }

        return $out;
    }

    /**
     * One aggregated Search Console query for a single page over the window. Null on a transient API
     * error or when GSC has no row for the page yet.
     *
     * @return array{impressions: int, clicks: int}|null
     */
    private function fetchTotals(string $property, string $pageUrl, int $days): ?array
    {
        $account = $this->connections->account();
        if ($account === null) {
            return null;
        }

        try {
            $json = $this->connections->request(
                $account,
                'post',
                rtrim($this->baseUrl, '/').'/sites/'.rawurlencode($property).'/searchAnalytics/query',
                ['json' => [
                    'startDate' => Carbon::now()->subDays($days)->format('Y-m-d'),
                    'endDate' => Carbon::now()->format('Y-m-d'),
                    'dimensionFilterGroups' => [[
                        'filters' => [[
                            'dimension' => 'page',
                            'operator' => 'equals',
                            'expression' => $pageUrl,
                        ]],
                    ]],
                    'rowLimit' => 1,
                ]],
            );
        } catch (GoogleException) {
            return null;
        }

        $rows = (array) ($json['rows'] ?? []);
        if ($rows === [] || ! is_array($rows[0])) {
            return null;
        }

        return [
            'impressions' => (int) ($rows[0]['impressions'] ?? 0),
            'clicks' => (int) ($rows[0]['clicks'] ?? 0),
        ];
    }

    /** The page's canonical URL (GSC's `page` dimension key) from the site domain + path, or null. */
    private function pageUrl(Site $site, string $path): ?string
    {
        $base = rtrim((string) $site->domain_url, '/');
        if ($base === '') {
            return null;
        }

        return $base.'/'.ltrim($path, '/');
    }
}
