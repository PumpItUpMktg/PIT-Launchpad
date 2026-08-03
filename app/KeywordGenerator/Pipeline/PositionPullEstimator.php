<?php

namespace App\KeywordGenerator\Pipeline;

use App\Enums\MarketTier;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * Estimates the DataForSEO spend of an on-demand ranking pull ({@see SitePipelineRefresher::trackNow})
 * BEFORE it runs, so the confirmation disclaimer shows a real number. The force track path posts, per
 * scored keyword: one organic SERP task (when the site has a resolvable host) + one local grid of
 * grid_size² Google Maps tasks (when the site has a Priority-tier market). The task count is exact;
 * the dollar figure multiplies it by config-driven approximate DataForSEO Standard-queue rates.
 */
class PositionPullEstimator
{
    public function estimate(Site $site): PositionPullEstimate
    {
        $keywords = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('status', 'scored')
            ->count();

        $hasHost = $this->host($site->domain_url) !== null;
        $hasPriorityMarket = Market::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('tier', MarketTier::Priority->value)
            ->exists();

        $gridPoints = max(1, (int) config('services.dataforseo.grid_size', 3)) ** 2;

        $organicTasks = $hasHost ? $keywords : 0;
        $localTasks = $hasPriorityMarket ? $keywords * $gridPoints : 0;

        $cost = $organicTasks * (float) config('services.dataforseo.serp_task_cost', 0.0012)
            + $localTasks * (float) config('services.dataforseo.maps_task_cost', 0.002);

        return new PositionPullEstimate(
            keywords: $keywords,
            gridPoints: $gridPoints,
            organicTasks: $organicTasks,
            localTasks: $localTasks,
            estimatedCost: $cost,
            hasHost: $hasHost,
            hasPriorityMarket: $hasPriorityMarket,
        );
    }

    /** Mirror of the refresher's host resolution — an organic pull only fires with a resolvable host. */
    private function host(?string $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $host = parse_url(str_contains($url, '://') ? $url : 'https://'.$url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
