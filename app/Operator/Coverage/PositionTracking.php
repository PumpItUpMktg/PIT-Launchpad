<?php

namespace App\Operator\Coverage;

use App\Enums\BeatabilityLane;
use App\Models\Keyword;
use App\Models\PositionSnapshot;
use App\Models\RefreshEvent;
use App\Models\Scopes\SiteScope;
use Illuminate\Support\Collection;

/**
 * Reads the §5 position-tracking time-series into the §7b workspace: the latest
 * organic standing, per-market local-pack standings, a cannibalization flag
 * (multiple owned URLs ranking for one keyword), and refresh-ROI markers (the
 * RefreshEvent count on the keyword's target content, overlaid on the rank
 * series).
 */
class PositionTracking
{
    public function forKeyword(Keyword $keyword): KeywordStandings
    {
        /** @var Collection<int, PositionSnapshot> $snapshots */
        $snapshots = PositionSnapshot::withoutGlobalScope(SiteScope::class)
            ->where('keyword_id', $keyword->id)
            ->orderByDesc('captured_at')
            ->get();

        $organic = $snapshots->first(fn (PositionSnapshot $s) => $s->lane === BeatabilityLane::Organic);

        return new KeywordStandings(
            keyword: $keyword,
            organicRank: $organic?->rank,
            organicUrl: $organic?->ranking_url,
            capturedAt: $organic?->captured_at?->toIso8601String(),
            localByMarket: $this->localByMarket($snapshots),
            cannibalizing: $this->cannibalizing($snapshots),
            refreshCount: $this->refreshCount($keyword),
            organicSeries: $this->organicSeries($snapshots),
        );
    }

    /**
     * Latest local-pack standing per market.
     *
     * @param  Collection<int, PositionSnapshot>  $snapshots
     * @return list<array{market_id: string|null, market_name: string, rank: int|null, captured_at: string|null}>
     */
    private function localByMarket(Collection $snapshots): array
    {
        return $snapshots
            ->filter(fn (PositionSnapshot $s) => $s->lane === BeatabilityLane::LocalPack)
            ->groupBy('market_id')
            ->map(fn (Collection $rows) => $rows->first())
            ->map(fn (PositionSnapshot $s) => [
                'market_id' => $s->market_id,
                'market_name' => (string) $s->market->name,
                'rank' => $s->rank,
                'captured_at' => $s->captured_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Cannibalization: more than one distinct owned URL ranking for the keyword
     * in its latest capture (the persisted proxy for §5's live SERP detector).
     *
     * @param  Collection<int, PositionSnapshot>  $snapshots
     */
    private function cannibalizing(Collection $snapshots): bool
    {
        $organic = $snapshots->filter(fn (PositionSnapshot $s) => $s->lane === BeatabilityLane::Organic);

        if ($organic->isEmpty()) {
            return false;
        }

        $latest = $organic->first()->captured_at;

        $urls = $organic
            ->filter(fn (PositionSnapshot $s) => $latest !== null && $s->captured_at?->equalTo($latest))
            ->map(fn (PositionSnapshot $s) => $s->ranking_url)
            ->filter()
            ->unique();

        return $urls->count() > 1;
    }

    private function refreshCount(Keyword $keyword): int
    {
        if ($keyword->target_content_id === null) {
            return 0;
        }

        return RefreshEvent::withoutGlobalScope(SiteScope::class)
            ->where('content_id', $keyword->target_content_id)
            ->count();
    }

    /**
     * @param  Collection<int, PositionSnapshot>  $snapshots
     * @return list<array{captured_at: string, rank: int|null}>
     */
    private function organicSeries(Collection $snapshots): array
    {
        return $snapshots
            ->filter(fn (PositionSnapshot $s) => $s->lane === BeatabilityLane::Organic)
            ->sortBy('captured_at')
            ->map(fn (PositionSnapshot $s) => [
                'captured_at' => (string) $s->captured_at?->toIso8601String(),
                'rank' => $s->rank,
            ])
            ->values()
            ->all();
    }
}
