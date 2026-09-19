<?php

namespace App\GeoGrid;

use App\Jobs\RunCoverageScan;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\TownRank\TownRankBoard;

/**
 * "Run the GBP report for every keyword at this office" — the whole-location version of the per-card
 * button, priced before it spends.
 *
 * Coverage is billed one DataForSEO Maps request per served town per keyword, so a single click here is
 * towns × keywords: at 54 towns and 34 keywords that is ~1,800 requests. The number and its cost are
 * computed the same way whether they are being SHOWN beside the button or SPENT by it — one plan(), so
 * the figure an operator agreed to is the figure that is posted.
 *
 * A keyword whose scan is still collecting is left alone rather than re-posted: paying twice for an
 * answer already in flight is the specific waste this guards.
 */
final class CoverageRunAll
{
    public function __construct(
        private readonly CoverageGrid $coverage,
        private readonly TownRankBoard $board,
    ) {}

    /**
     * @return array{towns: int, keywords: list<Keyword>, tracked: int, pending: int, requests: int, cost: float, ceiling: int, over_ceiling: bool}
     */
    public function plan(Site $site, Location $location): array
    {
        $towns = $this->coverage->count($location);

        // Keywords still collecting here — one request per town each, already paid for.
        $pending = GeoGridScan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->where('location_id', $location->id)
            ->where('mode', 'coverage')->where('status', 'pending')
            ->pluck('keyword_id')->flip();

        $tracked = 0;
        $keywords = [];
        foreach ($this->board->keywords($site) as $entry) {
            $keyword = Keyword::withoutGlobalScope(SiteScope::class)
                ->where('site_id', $site->id)->whereKey($entry['keyword_id'])->first();
            if ($keyword === null) {
                continue;
            }
            $tracked++;
            if ($pending->has((string) $keyword->id)) {
                continue;
            }
            $keywords[] = $keyword;
        }

        $requests = $towns * count($keywords);
        $ceiling = max(0, (int) config('launchpad.geo_grid.request_ceiling', 5000));

        return [
            'towns' => $towns,
            'keywords' => $keywords,
            'tracked' => $tracked,
            'pending' => $tracked - count($keywords),
            'requests' => $requests,
            'cost' => round($requests * (float) config('launchpad.geo_grid.cost_per_request', 0.002), 2),
            'ceiling' => $ceiling,
            'over_ceiling' => $ceiling > 0 && $requests > $ceiling,
        ];
    }

    /**
     * Queue one coverage scan per runnable keyword. Nothing is posted over the ceiling — the whole run is
     * refused rather than trimmed, so a partial spend can never be mistaken for the full picture.
     *
     * @return array{queued: int, requests: int, cost: float, over_ceiling: bool}
     */
    public function run(Site $site, Location $location): array
    {
        $plan = $this->plan($site, $location);
        if ($plan['over_ceiling'] || $plan['keywords'] === [] || $plan['towns'] === 0) {
            return ['queued' => 0, 'requests' => $plan['requests'], 'cost' => $plan['cost'], 'over_ceiling' => $plan['over_ceiling']];
        }

        foreach ($plan['keywords'] as $keyword) {
            RunCoverageScan::dispatch((string) $location->id, (string) $keyword->id);
        }

        return ['queued' => count($plan['keywords']), 'requests' => $plan['requests'], 'cost' => $plan['cost'], 'over_ceiling' => false];
    }
}
