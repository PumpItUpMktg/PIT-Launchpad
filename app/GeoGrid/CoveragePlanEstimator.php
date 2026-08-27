<?php

namespace App\GeoGrid;

use App\Models\Location;

/**
 * The rough per-run cost estimate for a coverage scan plan: one DataForSEO Maps request per served town per
 * keyword. Takes a live keyword count (not a persisted plan) so the operator sees the number update as they
 * pick keywords in the form, before saving.
 */
final class CoveragePlanEstimator
{
    public function __construct(private readonly CoverageGrid $coverage) {}

    /**
     * @return array{towns: int, keywords: int, requests: int, cost: float}
     */
    public function estimate(Location $location, int $keywordCount): array
    {
        $towns = $this->coverage->count($location);
        $keywords = max(0, $keywordCount);
        $requests = $towns * $keywords;

        return [
            'towns' => $towns,
            'keywords' => $keywords,
            'requests' => $requests,
            'cost' => round($requests * (float) config('launchpad.geo_grid.cost_per_request', 0.002), 2),
        ];
    }
}
