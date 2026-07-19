<?php

namespace App\KeywordGenerator\Derive;

use App\Models\KeywordCluster;
use App\Models\Scopes\SiteScope;
use App\Models\Service;
use App\Models\Site;

/**
 * The business-development output of keyword-first: demand clusters above a volume threshold that NO
 * service covers — "Crawl space encapsulation — 3,360/mo — no matching service. Add it?" A cluster is
 * covered when a real (unflagged) service maps to it; flagged nearest-matches don't count as coverage.
 * Surfaced on the Silos step with create-service / dismiss actions (wired in L4).
 */
final class DemandWithoutServiceReport
{
    /**
     * @return list<array{cluster_id: string, label: string|null, head_term: string|null, volume: int|null}>
     */
    public function for(Site $site): array
    {
        $threshold = (int) config('launchpad.keyword_first.demand_report_volume', 500);

        $covered = Service::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('structure_home_flagged', false)
            ->whereNotNull('structure_home_cluster_id')
            ->pluck('structure_home_cluster_id')
            ->all();

        return KeywordCluster::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('dropped', false)
            ->where('volume', '>=', $threshold)
            ->whereNotIn('id', $covered)
            ->orderByDesc('volume')
            ->get()
            ->map(fn (KeywordCluster $c): array => [
                'cluster_id' => $c->id,
                'label' => $c->label,
                'head_term' => $c->head_term,
                'volume' => $c->volume,
            ])
            ->all();
    }
}
