<?php

namespace App\Client;

use App\Enums\BeatabilityLane;
use App\Models\PositionSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * The per-market local-pack visibility heatmap — the client's local search
 * footprint across their geo grid (§5 per-market local series). Latest local
 * standing per market.
 */
class LocalGrid
{
    /**
     * @return list<array{market_id: string|null, market_name: string, rank: int|null, captured_at: string|null}>
     */
    public function heatmap(Site $site): array
    {
        return PositionSnapshot::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->where('lane', BeatabilityLane::LocalPack->value)
            ->whereNotNull('market_id')
            ->orderByDesc('captured_at')
            ->get()
            ->groupBy('market_id')
            ->map(fn (Collection $rows) => $rows->first())
            ->map(fn (PositionSnapshot $s) => [
                'market_id' => $s->market_id,
                'market_name' => (string) $s->market->name,
                'rank' => $s->rank,
                'captured_at' => $s->captured_at?->toDateString(),
            ])
            ->values()
            ->all();
    }
}
