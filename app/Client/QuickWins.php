<?php

namespace App\Client;

use App\Enums\BeatabilityLane;
use App\Models\Keyword;
use App\Models\PositionSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * Quick-wins landed: early, low-difficulty keywords that have ranked — the
 * early-value / momentum proof (§5's quick-win build order paying off).
 */
class QuickWins
{
    /**
     * @return list<array{keyword_id: string, query: string, difficulty: int|null, rank: int}>
     */
    public function landed(Site $site, int $maxDifficulty = 30, int $topRank = 10): array
    {
        $keywords = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where(fn ($q) => $q->whereNull('difficulty')->orWhere('difficulty', '<=', $maxDifficulty))
            ->get()
            ->keyBy('id');

        if ($keywords->isEmpty()) {
            return [];
        }

        $bestRank = PositionSnapshot::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('lane', BeatabilityLane::Organic->value)
            ->whereIn('keyword_id', $keywords->keys())
            ->whereNotNull('rank')
            ->where('rank', '<=', $topRank)
            ->selectRaw('keyword_id, min(rank) as best')
            ->groupBy('keyword_id')
            ->pluck('best', 'keyword_id');

        $rows = [];
        foreach ($bestRank as $keywordId => $rank) {
            /** @var Keyword $keyword */
            $keyword = $keywords[$keywordId];
            $rows[] = [
                'keyword_id' => (string) $keywordId,
                'query' => (string) $keyword->query,
                'difficulty' => $keyword->difficulty,
                'rank' => (int) $rank,
            ];
        }

        usort($rows, fn (array $a, array $b) => $a['rank'] <=> $b['rank']);

        return $rows;
    }
}
