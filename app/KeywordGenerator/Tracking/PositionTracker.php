<?php

namespace App\KeywordGenerator\Tracking;

use App\Enums\BeatabilityLane;
use App\Integrations\LocalGrid\GridMetrics;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\PositionSnapshot;

/**
 * Records the two-lane position series: one organic series per keyword and a
 * per-market local-pack series.
 */
class PositionTracker
{
    /**
     * @param  array<string, mixed>  $serpFeatures
     */
    public function recordOrganic(Keyword $keyword, int $rank, ?string $rankingUrl = null, array $serpFeatures = []): PositionSnapshot
    {
        return PositionSnapshot::create([
            'site_id' => $keyword->site_id,
            'keyword_id' => $keyword->id,
            'market_id' => null,
            'lane' => BeatabilityLane::Organic,
            'rank' => $rank,
            'ranking_url' => $rankingUrl,
            'serp_features' => $serpFeatures,
            'captured_at' => now(),
        ]);
    }

    public function recordLocalGrid(Keyword $keyword, Market $market, GridMetrics $grid): PositionSnapshot
    {
        return PositionSnapshot::create([
            'site_id' => $keyword->site_id,
            'keyword_id' => $keyword->id,
            'market_id' => $market->id,
            'lane' => BeatabilityLane::LocalPack,
            'avg_rank' => $grid->avgRank,
            'pct_top3' => $grid->pctTop3,
            'coverage' => $grid->coverage,
            'captured_at' => now(),
        ]);
    }
}
