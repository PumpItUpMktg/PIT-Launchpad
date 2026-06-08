<?php

namespace App\Client;

use App\Enums\BeatabilityLane;
use App\Models\PositionSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * SEO progress: keywords that moved up the organic results, or newly ranked,
 * over the tracked window. Plain observed movement — no inflated claims.
 */
class RankingGains
{
    /**
     * @return list<array{keyword_id: string, from: int|null, to: int|null, improved: bool, is_new: bool}>
     */
    public function gains(Site $site): array
    {
        $byKeyword = PositionSnapshot::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('lane', BeatabilityLane::Organic->value)
            ->orderBy('captured_at')
            ->get()
            ->groupBy('keyword_id');

        $rows = [];
        foreach ($byKeyword as $keywordId => $snapshots) {
            /** @var Collection<int, PositionSnapshot> $snapshots */
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
