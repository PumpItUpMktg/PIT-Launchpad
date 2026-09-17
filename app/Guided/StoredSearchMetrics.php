<?php

namespace App\Guided;

use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\GscUrlQueryMonthly;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\PublicUrl;
use Illuminate\Support\Carbon;

/**
 * Search Console figures for a whole board, read from OUR OWN tables in one query each.
 *
 * The daily sync already writes every page's impressions, clicks and position into `gsc_url_daily`, and the
 * queries each page is found for into `gsc_url_query_monthly`. The cards were not reading any of it: each
 * one asked the Search Console client for its own page, which before #888 was a live Google call per
 * uncached page and after it a per-page cache lookup. Eleven cards cost 115 queries that way.
 *
 * So the board primes: two grouped queries for every page on screen, and the per-page code reads the map.
 * The vendor client stays where it belongs — filling those tables off-request — instead of being asked for
 * data that is already sitting in Postgres.
 */
final class StoredSearchMetrics
{
    /** The rolling window the cards report, matching what the per-page client asked for. */
    public const WINDOW_DAYS = 28;

    /** Queries shown per page — the terms it is actually found for, best first. */
    public const QUERIES_PER_PAGE = 5;

    /**
     * Totals per page URL for the window: impressions, clicks, and the impression-weighted position.
     *
     * @param  iterable<Content>  $pages
     * @return array<string, array{impressions: int, clicks: int, ctr: float, position: float|null}>
     */
    public function totals(Site $site, iterable $pages): array
    {
        $urls = $this->urls($site, $pages);
        if ($urls === []) {
            return [];
        }

        $rows = GscUrlDaily::query()->withoutGlobalScope(SiteScope::class)->toBase()
            ->where('site_id', $site->id)
            ->whereIn('url', array_keys($urls))
            ->where('date', '>=', Carbon::now()->subDays(self::WINDOW_DAYS)->toDateString())
            ->selectRaw('url, sum(impressions) as impressions, sum(clicks) as clicks, sum(position * impressions) as weighted')
            ->groupBy('url')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $impressions = (int) $row->impressions;
            $clicks = (int) $row->clicks;
            $out[$urls[(string) $row->url]] = [
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $impressions > 0 ? round($clicks / $impressions, 4) : 0.0,
                // Averaging a position across days only means anything weighted by how often it was seen.
                'position' => $impressions > 0 && $row->weighted !== null ? round((float) $row->weighted / $impressions, 1) : null,
            ];
        }

        return $out;
    }

    /**
     * What each page is actually found for — its top queries this month and last, most impressions first.
     *
     * @param  iterable<Content>  $pages
     * @return array<string, list<array{query: string, clicks: int, impressions: int, position: float|null}>>
     */
    public function queries(Site $site, iterable $pages): array
    {
        $urls = $this->urls($site, $pages);
        if ($urls === []) {
            return [];
        }

        $rows = GscUrlQueryMonthly::query()->withoutGlobalScope(SiteScope::class)->toBase()
            ->where('site_id', $site->id)
            ->whereIn('url', array_keys($urls))
            ->where('month', '>=', Carbon::now()->startOfMonth()->subMonth()->toDateString())
            ->selectRaw('url, query, sum(impressions) as impressions, sum(clicks) as clicks, sum(position * impressions) as weighted')
            ->groupBy('url', 'query')
            ->orderByDesc('impressions')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $key = $urls[(string) $row->url];
            if (count($out[$key] ?? []) >= self::QUERIES_PER_PAGE) {
                continue;
            }
            $impressions = (int) $row->impressions;
            $out[$key][] = [
                'query' => (string) $row->query,
                'clicks' => (int) $row->clicks,
                'impressions' => $impressions,
                'position' => $impressions > 0 && $row->weighted !== null ? round((float) $row->weighted / $impressions, 1) : null,
            ];
        }

        return $out;
    }

    /**
     * Stored URL → content id, for every page that has one. Search Console keys on the canonical URL, and
     * the sync stores it as it comes back, so both the slash and slashless forms are looked up rather than
     * assuming which one a site's permalinks settled on.
     *
     * @param  iterable<Content>  $pages
     * @return array<string, string>
     */
    private function urls(Site $site, iterable $pages): array
    {
        $out = [];
        foreach ($pages as $page) {
            $url = PublicUrl::forContent($site->domain_url, $page);
            if ($url === null) {
                continue;
            }
            $id = (string) $page->id;
            $out[rtrim($url, '/')] = $id;
            $out[rtrim($url, '/').'/'] = $id;
        }

        return $out;
    }
}
