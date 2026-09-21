<?php

namespace App\Operator\Coverage;

use App\Guided\StoredSearchMetrics;
use App\Models\Content;
use App\Models\GscUrlDaily;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\PublicUrl;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Where a page actually ranks, and how much it is seen — the companion to {@see PageImpressions}, which
 * answers only whether a page has been seen at all.
 *
 * Position is IMPRESSION-WEIGHTED, never a flat mean across days: one quiet Sunday at position 3 must not
 * outvote a busy week at 25. This is the same rule {@see StoredSearchMetrics} applies, so a page reads the
 * same number here as it does on its own card.
 *
 * Both halves come back from one pass because every caller that wants one wants the other — "is this page
 * in striking distance" and "is this page a strong enough source to link from" are the same query asked
 * about different rows.
 */
class PagePositions
{
    /**
     * Blended position and total impressions per content id, over the trailing window.
     *
     * A page with no positioned impressions is ABSENT rather than defaulted: there is no honest position
     * to give it, and a placeholder would quietly enter the striking-distance band it was never measured in.
     *
     * @param  Collection<int, Content>  $pages
     * @return array<string, array{position: float, impressions: int}>
     */
    public function for(Site $site, Collection $pages, ?int $windowDays = null): array
    {
        $byUrl = [];
        foreach ($pages as $page) {
            $url = PublicUrl::forContent($site->domain_url, $page);
            if ($url === null) {
                continue;
            }
            $id = (string) $page->id;
            $byUrl[rtrim($url, '/')] = $id;
            $byUrl[rtrim($url, '/').'/'] = $id;
        }
        if ($byUrl === []) {
            return [];
        }

        $since = Carbon::now()->subDays($windowDays ?? StoredSearchMetrics::WINDOW_DAYS)->toDateString();

        $rows = GscUrlDaily::query()->withoutGlobalScope(SiteScope::class)->toBase()
            ->where('site_id', $site->id)
            ->whereIn('url', array_keys($byUrl))
            ->where('date', '>=', $since)
            ->where('impressions', '>', 0)
            ->whereNotNull('position')
            ->selectRaw('url, sum(position * impressions) as weighted, sum(impressions) as impressions')
            ->groupBy('url')
            ->get();

        // Several URL forms can resolve to one page, so totals accumulate per CONTENT rather than per row.
        $acc = [];
        foreach ($rows as $row) {
            $id = $byUrl[(string) $row->url] ?? null;
            if ($id === null) {
                continue;
            }
            $acc[$id]['weighted'] = ($acc[$id]['weighted'] ?? 0.0) + (float) $row->weighted;
            $acc[$id]['impressions'] = ($acc[$id]['impressions'] ?? 0) + (int) $row->impressions;
        }

        $out = [];
        foreach ($acc as $id => $sums) {
            if ($sums['impressions'] > 0) {
                $out[$id] = [
                    'position' => round($sums['weighted'] / $sums['impressions'], 1),
                    'impressions' => $sums['impressions'],
                ];
            }
        }

        return $out;
    }
}
