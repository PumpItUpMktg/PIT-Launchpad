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
 * Which pages have earned Search impressions — the ONE place that question is answered, so the surfaces
 * that call a page indexed on the strength of impressions cannot drift apart on what "has impressions"
 * means.
 *
 * Impressions are the second half of the index verdict, and the stronger half: a page cannot be shown in
 * results without being in the index, so an impression is proof. Their absence proves nothing — a page
 * can be perfectly indexed and simply never surface — which is why every caller ORs this with the URL
 * Inspection verdict and never ANDs it.
 *
 * Two horizons, because they answer different questions:
 *   • {@see recent()} — inside {@see StoredSearchMetrics::WINDOW_DAYS}, matching what a per-page card
 *     shows. "Is it in the index AND still earning."
 *   • {@see ever()} — any impression ever recorded. "Is it in the index." A page that earned impressions
 *     last quarter and none since is still indexed; it is just quiet.
 *
 * Both forms of every URL are looked up (slash and slashless) for the same reason {@see StoredSearchMetrics}
 * does it: Search Console stores the canonical URL as it comes back, and which form a site's permalinks
 * settled on is not ours to assume.
 */
class PageImpressions
{
    /**
     * Content ids that earned impressions inside the card window, as a lookup set.
     *
     * @param  Collection<int, Content>  $pages
     * @return array<string, true>
     */
    public function recent(Site $site, Collection $pages): array
    {
        return $this->resolve($site, $pages)[0];
    }

    /**
     * Content ids that have ever earned an impression, as a lookup set.
     *
     * @param  Collection<int, Content>  $pages
     * @return array<string, true>
     */
    public function ever(Site $site, Collection $pages): array
    {
        return $this->resolve($site, $pages)[1];
    }

    /**
     * Both horizons in one pass — callers that need the pair (the reconciliation report) get them without
     * querying twice.
     *
     * @param  Collection<int, Content>  $pages
     * @return array{0: array<string, true>, 1: array<string, true>}
     */
    public function resolve(Site $site, Collection $pages): array
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
            return [[], []];
        }

        $rows = GscUrlDaily::query()->withoutGlobalScope(SiteScope::class)->toBase()
            ->where('site_id', $site->id)
            ->whereIn('url', array_keys($byUrl))
            ->where('impressions', '>', 0)
            ->selectRaw('url, max(date) as newest')
            ->groupBy('url')
            ->get();

        $cutoff = Carbon::now()->subDays(StoredSearchMetrics::WINDOW_DAYS)->toDateString();
        $recent = [];
        $ever = [];
        foreach ($rows as $row) {
            $id = $byUrl[(string) $row->url] ?? null;
            if ($id === null) {
                continue;
            }
            $ever[$id] = true;
            if (Carbon::parse((string) $row->newest)->toDateString() >= $cutoff) {
                $recent[$id] = true;
            }
        }

        return [$recent, $ever];
    }
}
