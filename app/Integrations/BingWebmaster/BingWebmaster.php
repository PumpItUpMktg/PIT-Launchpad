<?php

namespace App\Integrations\BingWebmaster;

use App\Integrations\SearchConsole\GoogleSearchConsole;
use App\Integrations\SearchConsole\PageQuery;
use App\Integrations\SearchConsole\PageSearchStats;
use App\Models\Site;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * The card-facing Bing Webmaster Tools adapter — bridges the agency BWT API key onto the Live boards'
 * {@see BingWebmasterProvider} contract, the Bing twin of {@see GoogleSearchConsole}.
 *
 * Auth is deliberately lighter than Google's OAuth grant: a SINGLE account-scoped API key (passed as
 * `?apikey=`), and the per-Site `bing_site_url` (the site verified in BWT — easiest via BWT's "import
 * from Google Search Console") selects which site to read, exactly as `gsc_property` scopes the shared
 * Google grant. A tenant is "connected" when the key is configured AND this Site has a `bing_site_url`.
 *
 * One `GetPageQueryStats` call per page (the queries the page ranked for on Bing), cached per
 * (site × page × window); `pageStats` aggregates those rows and `pageQueries` returns the top of them
 * from the SAME cached fetch, so a board render doesn't fan out. A transient API error or a page with
 * no Bing data yet resolves to null / [] (the "collecting" cell), never an exception into the board.
 *
 * NOTE: BWT's JSON responses wrap the payload in a `d` envelope; field names (Query / Impressions /
 * Clicks / AvgImpressionPosition) follow BWT's documented shape. The parsing is defensive; validate the
 * exact field mapping on the first real connection (it's mock-first: {@see NullBingWebmaster} is the
 * default until the key is set).
 */
class BingWebmaster implements BingWebmasterProvider
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly CacheRepository $cache,
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://ssl.bing.com/webmaster/api.svc/json',
        private readonly int $timeout = 15,
        private readonly int $cacheTtl = 21600,
    ) {}

    public function connected(Site $site): bool
    {
        return trim($this->apiKey) !== '' && is_string($site->bing_site_url) && trim($site->bing_site_url) !== '';
    }

    public function pageStats(Site $site, string $path, int $days = 28): ?PageSearchStats
    {
        $rows = $this->rows($site, $path, $days);
        if ($rows === null || $rows === []) {
            return null;
        }

        return new PageSearchStats(
            impressions: array_sum(array_column($rows, 'impressions')),
            clicks: array_sum(array_column($rows, 'clicks')),
            days: $days,
        );
    }

    /**
     * @return list<PageQuery>
     */
    public function pageQueries(Site $site, string $path, int $days = 28, int $limit = 8): array
    {
        $rows = $this->rows($site, $path, $days) ?? [];

        return array_map(
            fn (array $r): PageQuery => new PageQuery($r['query'], $r['clicks'], $r['impressions'], $r['ctr'], $r['position']),
            array_slice($rows, 0, max(1, $limit)),
        );
    }

    /**
     * The page's Bing queries — normalized, grouped by query, impressions-desc — cached per
     * (site × page × window). Null when disconnected or on a transient error; [] when Bing has the page
     * but no query data. Shared by pageStats + pageQueries so only one API call is made per page.
     *
     * @return list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}>|null
     */
    private function rows(Site $site, string $path, int $days): ?array
    {
        if (! $this->connected($site)) {
            return null;
        }

        $siteUrl = rtrim((string) $site->bing_site_url, '/');
        $pageUrl = $this->pageUrl($site, $path);
        if ($pageUrl === null) {
            return null;
        }

        $key = 'bing:pagequeries:'.md5($siteUrl.'|'.$pageUrl.'|'.$days);

        $cached = $this->cache->remember($key, $this->cacheTtl, function () use ($siteUrl, $pageUrl): array {
            $rows = $this->fetch($siteUrl, $pageUrl);

            return $rows === null ? ['none' => true] : ['rows' => $rows];
        });

        if (isset($cached['none'])) {
            return null;
        }

        /** @var list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}> */
        return $cached['rows'];
    }

    /**
     * GET GetPageQueryStats for a page, grouped by query (impression-weighted position), sorted by
     * impressions desc. Null on a transient error; [] when Bing returns no query rows for the page.
     *
     * @return list<array{query: string, clicks: int, impressions: int, ctr: float, position: float}>|null
     */
    private function fetch(string $siteUrl, string $pageUrl): ?array
    {
        try {
            $response = $this->http->timeout($this->timeout)->acceptJson()->get(
                rtrim($this->baseUrl, '/').'/GetPageQueryStats',
                ['apikey' => $this->apiKey, 'siteUrl' => $siteUrl, 'page' => $pageUrl],
            );
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        // BWT wraps the payload in a `d` envelope.
        $data = $response->json('d');
        if (! is_array($data)) {
            return null;
        }

        $grouped = [];
        foreach ($data as $row) {
            if (! is_array($row)) {
                continue;
            }
            $query = trim((string) ($row['Query'] ?? ''));
            if ($query === '') {
                continue;
            }
            $impr = (int) ($row['Impressions'] ?? 0);
            $clicks = (int) ($row['Clicks'] ?? 0);
            $pos = (float) ($row['AvgImpressionPosition'] ?? 0);

            $k = mb_strtolower($query);
            if (! isset($grouped[$k])) {
                $grouped[$k] = ['query' => $query, 'clicks' => 0, 'impressions' => 0, 'pos_weight' => 0.0];
            }
            $grouped[$k]['clicks'] += $clicks;
            $grouped[$k]['impressions'] += $impr;
            $grouped[$k]['pos_weight'] += $pos * $impr;
        }

        $out = [];
        foreach ($grouped as $g) {
            $impr = (int) $g['impressions'];
            $out[] = [
                'query' => (string) $g['query'],
                'clicks' => (int) $g['clicks'],
                'impressions' => $impr,
                'ctr' => $impr > 0 ? round($g['clicks'] / $impr * 100, 1) : 0.0,
                'position' => $impr > 0 ? round($g['pos_weight'] / $impr, 1) : 0.0,
            ];
        }

        usort($out, fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);

        return $out;
    }

    /** The page's Bing URL key from the site domain + path, or null. */
    private function pageUrl(Site $site, string $path): ?string
    {
        $base = rtrim((string) $site->domain_url, '/');
        if ($base === '') {
            return null;
        }

        return $base.'/'.ltrim($path, '/');
    }
}
