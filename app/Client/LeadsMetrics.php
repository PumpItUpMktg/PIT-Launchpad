<?php

namespace App\Client;

use App\Models\Conversion;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * The leads/conversions headline — the revenue *proxy*. Totals + trend over time,
 * shown honestly: no per-action attribution, no fabricated ROI. Reads whatever
 * the (mock-first GA4/GHL) Conversion model holds.
 */
class LeadsMetrics
{
    public function total(Site $site, int $days = 90): int
    {
        return (int) Conversion::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('occurred_at', '>=', now()->subDays($days))
            ->sum('count');
    }

    /**
     * Weekly lead totals over the window (oldest → newest), zero-filled.
     *
     * @return array<string, int>
     */
    public function trend(Site $site, int $weeks = 8): array
    {
        $since = now()->startOfWeek()->subWeeks($weeks - 1);

        $buckets = [];
        for ($i = 0; $i < $weeks; $i++) {
            $buckets[(clone $since)->addWeeks($i)->isoFormat('GGGG-[W]WW')] = 0;
        }

        Conversion::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('occurred_at', '>=', $since)
            ->get(['count', 'occurred_at'])
            ->each(function (Conversion $c) use (&$buckets): void {
                $label = $c->occurred_at->isoFormat('GGGG-[W]WW');
                if (array_key_exists($label, $buckets)) {
                    $buckets[$label] += $c->count;
                }
            });

        return $buckets;
    }
}
