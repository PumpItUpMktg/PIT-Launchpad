<?php

namespace App\Publishing\Redirects;

use App\Models\GscUrlDaily;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * The URL inventory Google actually holds for a site, read from the GSC daily
 * time-series ({@see GscUrlDaily}). This is the authoritative list of
 * every URL that has earned an impression over the retained window — the input
 * the legacy-redirect planner diffs against the current published pages to find
 * the old-site URLs still indexed under this domain.
 *
 * Keyed on the raw GSC `url` string (GSC returns the canonical, so variants are
 * rare); callers normalize to a path for matching. `topQuery()` names the single
 * highest-impression query a URL ranked for, which routes an orphaned URL to the
 * new page that best covers that intent.
 */
class GscUrlInventory
{
    /**
     * Every URL Google has shown for the site, with its lifetime impressions +
     * clicks over the retained window, most impressions first.
     *
     * @return list<array{url: string, impressions: int, clicks: int}>
     */
    public function urlTotals(Site $site): array
    {
        return DB::table('gsc_url_daily')
            ->where('site_id', $site->id)
            ->groupBy('url')
            ->selectRaw('url, sum(impressions) as impressions, sum(clicks) as clicks')
            ->orderByRaw('sum(impressions) desc')
            ->get()
            ->map(fn (object $r): array => [
                'url' => (string) $r->url,
                'impressions' => (int) $r->impressions,
                'clicks' => (int) $r->clicks,
            ])
            ->all();
    }

    /**
     * The highest-impression query a single URL ranked for (across the daily and
     * monthly-rollup grains), or null if none. Used to route an orphaned URL by
     * the intent it actually earned rank on.
     */
    public function topQuery(Site $site, string $url): ?string
    {
        $totals = [];
        foreach (['gsc_url_query_daily', 'gsc_url_query_monthly'] as $table) {
            $rows = DB::table($table)
                ->where('site_id', $site->id)
                ->where('url', $url)
                ->groupBy('query')
                ->selectRaw('query, sum(impressions) as impressions')
                ->get();
            foreach ($rows as $r) {
                $q = (string) $r->query;
                $totals[$q] = ($totals[$q] ?? 0) + (int) $r->impressions;
            }
        }

        if ($totals === []) {
            return null;
        }
        arsort($totals);

        $query = (string) array_key_first($totals);

        return $query !== '' ? $query : null;
    }
}
