<?php

namespace App\Ranking;

use App\Client\RankingGains;
use App\Enums\BeatabilityLane;
use App\Models\PositionSnapshot;
use App\Models\Scopes\SiteScope;
use App\Operator\Coverage\RankingStandings;

/**
 * The single site-wide organic-movers computation: keywords that moved UP the organic results, or
 * newly ranked, over the tracked window — first-vs-last organic rank from the persisted
 * `position_snapshots`. Plain observed movement, no inflated claims and no attribution.
 *
 * Extracted so the client dashboard ({@see RankingGains}) and the operator Rankings surface
 * ({@see RankingStandings}) compute "improved / newly ranked" ONCE, not twice.
 * Reads only the snapshot store — never a live SERP/DataForSEO provider.
 */
class OrganicMovers
{
    /**
     * @return list<array{keyword_id: string, from: int|null, to: int|null, improved: bool, is_new: bool}>
     */
    public function forSite(string $siteId): array
    {
        $byKeyword = PositionSnapshot::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('lane', BeatabilityLane::Organic->value)
            ->orderBy('captured_at')
            ->get(['keyword_id', 'rank', 'captured_at'])
            ->groupBy('keyword_id');

        $rows = [];
        foreach ($byKeyword as $keywordId => $snapshots) {
            $first = $snapshots->first()->rank;
            $last = $snapshots->last()->rank;

            $isNew = $first === null && $last !== null;
            $improved = $first !== null && $last !== null && $last < $first;

            if ($improved || $isNew) {
                $rows[] = [
                    'keyword_id' => (string) $keywordId,
                    'from' => $first,
                    'to' => $last,
                    'improved' => $improved,
                    'is_new' => $isNew,
                ];
            }
        }

        return $rows;
    }
}
