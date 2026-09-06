<?php

namespace App\Operator\Coverage;

use App\Enums\BeatabilityLane;
use App\Filament\Pages\RankingsBoard;
use App\Models\Keyword;
use App\Models\PositionSnapshot;
use App\Models\Scopes\SiteScope;
use App\Ranking\OrganicMovers;
use Illuminate\Support\Collection;

/**
 * The read-model behind the operator **Rankings** surface (§7b two-lane position tracking): organic
 * movers, per-market local-pack standings, and cannibalization flags for one tenant. UI-agnostic and
 * testable; the Filament page ({@see RankingsBoard}) is thin over it.
 *
 * HTTP-free by construction — reads ONLY the persisted `position_snapshots` store (+ keyword/market
 * names), never a live SERP/DataForSEO provider (the provider sits behind the capture path that WRITES
 * snapshots, never a render path). Movement is observed, not attributed — no ROI claims. The site-wide
 * movers logic is the shared {@see OrganicMovers} kernel (also behind the client dashboard), computed once.
 */
class RankingStandings
{
    public function __construct(private OrganicMovers $movers) {}

    /**
     * @return array{
     *     summary: array{tracked: int, improved: int, newly_ranked: int, cannibalized: int, markets_tracked: int},
     *     movers: list<array{keyword_id: string, query: string, from: int|null, to: int|null, delta: int|null, improved: bool, is_new: bool}>,
     *     cannibalized: list<array{keyword_id: string, query: string, urls: int}>,
     *     local: list<array{market_id: string|null, market_name: string, keywords: int, avg_rank: float|null, in_top3: int}>,
     *     last_captured_at: string|null
     * }
     */
    public function for(?string $siteId): array
    {
        if ($siteId === null) {
            return ['summary' => ['tracked' => 0, 'improved' => 0, 'newly_ranked' => 0, 'cannibalized' => 0, 'markets_tracked' => 0], 'movers' => [], 'cannibalized' => [], 'local' => [], 'last_captured_at' => null];
        }

        // The freshness anchor: the newest capture across BOTH lanes. Null → never checked (a real state,
        // rendered as a quiet "never checked" stamp), distinct from "checked, nothing moved".
        $lastCaptured = PositionSnapshot::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->max('captured_at');

        // Organic snapshots, newest-first, grouped per keyword — the source for tracked-count + cannibalization.
        $organic = PositionSnapshot::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('lane', BeatabilityLane::Organic->value)
            ->orderByDesc('captured_at')
            ->get(['keyword_id', 'rank', 'ranking_url', 'captured_at'])
            ->groupBy('keyword_id');

        $queries = Keyword::withoutGlobalScope(SiteScope::class)
            ->whereIn('id', $organic->keys()->all())
            ->pluck('query', 'id');
        $nameOf = fn (string $id): string => (string) ($queries[$id] ?? '—');

        // Movers — the SHARED kernel — enriched with the keyword text and a delta, best gains first.
        $movers = array_map(function (array $m) use ($nameOf): array {
            $delta = $m['from'] !== null && $m['to'] !== null ? $m['from'] - $m['to'] : null;

            return $m + ['query' => $nameOf($m['keyword_id']), 'delta' => $delta];
        }, $this->movers->forSite($siteId));
        usort($movers, fn (array $a, array $b): int => [$b['improved'], $b['delta'] ?? -1] <=> [$a['improved'], $a['delta'] ?? -1]);

        // Cannibalization — >1 distinct owned URL in a keyword's latest organic capture.
        $cannibalized = [];
        foreach ($organic as $keywordId => $snaps) {
            $latest = $snaps->first()->captured_at;
            $urls = $snaps
                ->filter(fn (PositionSnapshot $s): bool => $latest !== null && $s->captured_at?->equalTo($latest))
                ->pluck('ranking_url')->filter()->unique();
            if ($urls->count() > 1) {
                $cannibalized[] = ['keyword_id' => (string) $keywordId, 'query' => $nameOf((string) $keywordId), 'urls' => $urls->count()];
            }
        }

        $local = $this->localByMarket($siteId);

        return [
            'summary' => [
                'tracked' => $organic->count(),
                'improved' => count(array_filter($movers, fn (array $m): bool => $m['improved'])),
                'newly_ranked' => count(array_filter($movers, fn (array $m): bool => $m['is_new'])),
                'cannibalized' => count($cannibalized),
                'markets_tracked' => count($local),
            ],
            'movers' => $movers,
            'cannibalized' => $cannibalized,
            'local' => $local,
            'last_captured_at' => $lastCaptured !== null ? (string) $lastCaptured : null,
        ];
    }

    /**
     * Latest local-pack standing per (keyword × market), summarized per market.
     *
     * @return list<array{market_id: string|null, market_name: string, keywords: int, avg_rank: float|null, in_top3: int}>
     */
    private function localByMarket(string $siteId): array
    {
        $latest = PositionSnapshot::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->where('lane', BeatabilityLane::LocalPack->value)
            ->whereNotNull('market_id')
            ->orderByDesc('captured_at')
            ->with('market')
            ->get()
            ->groupBy(fn (PositionSnapshot $s): string => $s->keyword_id.'|'.$s->market_id)
            ->map(fn (Collection $rows): PositionSnapshot => $rows->first());

        return $latest
            ->groupBy('market_id')
            ->map(function (Collection $rows): array {
                $ranked = $rows->whereNotNull('rank');

                return [
                    'market_id' => $rows->first()->market_id,
                    'market_name' => (string) $rows->first()->market?->name,
                    'keywords' => $rows->count(),
                    'avg_rank' => $ranked->isNotEmpty() ? round((float) $ranked->avg('rank'), 1) : null,
                    'in_top3' => $rows->filter(fn (PositionSnapshot $s): bool => $s->rank !== null && $s->rank <= 3)->count(),
                ];
            })
            ->sortByDesc('keywords')
            ->values()
            ->all();
    }
}
