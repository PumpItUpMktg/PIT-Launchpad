<?php

namespace App\Publishing\Redirects;

use App\Metrics\UrlNormalizer;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * What a revival family actually ranks for — every query across every URL in it, not just the best one.
 *
 * A revived post REPLACES its family and 301s the originals onto it. Whatever the old pages earned that
 * the new one does not cover is gone, with no way back. So the brief decides how much of the traffic
 * survives, and briefing on a single query throws away the rest of the cluster by construction.
 *
 * Sump Pump Gurus makes the case plainly. Its largest family is eleven URLs earning 361,049 impressions,
 * briefed as "sump pump renovation cost". Its second is eleven URLs and 88,358, briefed as "sump pump
 * design ideas 2025". Neither phrase can carry a cluster that size — and "design ideas 2025" would send a
 * drafter somewhere the pages never were.
 *
 * Queries are summed across the family (both GSC grains, the same two {@see GscUrlInventory::topQuery}
 * reads) so a term earning moderately on six URLs ranks above one earning well on a single URL. That is
 * the term the family collectively owns, which is what a replacement has to hold.
 */
class RevivalBrief
{
    /** Enough to describe what a cluster covers; past this the tail is noise a drafter cannot act on. */
    public const MAX_QUERIES = 12;

    /** A query below this share of the family's best is not shaping the article. */
    private const MIN_SHARE = 0.02;

    /**
     * The family's queries, biggest first.
     *
     * @param  list<string>  $urls  the family's legacy paths or URLs
     * @return list<array{query: string, impressions: int}>
     */
    public function for(Site $site, array $urls, int $limit = self::MAX_QUERIES): array
    {
        $paths = [];
        foreach ($urls as $url) {
            $paths[UrlNormalizer::path($url)] = true;
        }
        if ($paths === []) {
            return [];
        }

        $totals = [];
        foreach (['gsc_url_query_daily', 'gsc_url_query_monthly'] as $table) {
            $rows = DB::table($table)
                ->where('site_id', $site->id)
                ->groupBy('url', 'query')
                ->selectRaw('url, query, sum(impressions) as impressions')
                ->get();

            foreach ($rows as $row) {
                // Matched on normalized PATH: the family carries paths, the series carries absolute URLs,
                // and a site that changed host or scheme would otherwise match nothing at all.
                if (! isset($paths[UrlNormalizer::path((string) $row->url)])) {
                    continue;
                }
                $query = trim((string) $row->query);
                if ($query === '') {
                    continue;
                }
                $totals[$query] = ($totals[$query] ?? 0) + (int) $row->impressions;
            }
        }

        if ($totals === []) {
            return [];
        }

        arsort($totals);
        $best = (int) reset($totals);
        $floor = (int) max(1, $best * self::MIN_SHARE);

        $out = [];
        foreach ($totals as $query => $impressions) {
            if ($impressions < $floor) {
                break;
            }
            $out[] = ['query' => (string) $query, 'impressions' => $impressions];
            if (count($out) >= max(1, $limit)) {
                break;
            }
        }

        return $out;
    }

    /**
     * The brief sentence a drafter reads — the queries the replacement has to hold, in order.
     *
     * @param  list<array{query: string, impressions: int}>  $queries
     */
    public function sentence(array $queries): string
    {
        if ($queries === []) {
            return '';
        }

        $parts = array_map(
            fn (array $q): string => sprintf('“%s” (%s)', $q['query'], number_format($q['impressions'])),
            $queries,
        );

        return count($parts) === 1
            ? 'It earns on '.$parts[0].'.'
            : 'It earns on these, biggest first — the replacement has to cover all of them, not only the first: '
                .implode(', ', $parts).'.';
    }
}
