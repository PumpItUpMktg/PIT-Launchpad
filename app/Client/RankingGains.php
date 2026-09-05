<?php

namespace App\Client;

use App\Models\Site;
use App\Ranking\OrganicMovers;

/**
 * SEO progress: keywords that moved up the organic results, or newly ranked,
 * over the tracked window. Plain observed movement — no inflated claims.
 *
 * The movement computation itself lives in the shared {@see OrganicMovers} kernel (also used by the
 * operator Rankings surface), so "improved / newly ranked" is computed in one place.
 */
class RankingGains
{
    public function __construct(private OrganicMovers $movers) {}

    /**
     * @return list<array{keyword_id: string, from: int|null, to: int|null, improved: bool, is_new: bool}>
     */
    public function gains(Site $site): array
    {
        return $this->movers->forSite($site->id);
    }

    /**
     * @return array{improved: int, new: int}
     */
    public function summary(Site $site): array
    {
        $gains = $this->gains($site);

        return [
            'improved' => count(array_filter($gains, fn (array $g) => $g['improved'])),
            'new' => count(array_filter($gains, fn (array $g) => $g['is_new'])),
        ];
    }
}
