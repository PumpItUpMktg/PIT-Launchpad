<?php

namespace App\Operator\Coverage;

use App\Enums\MarketTier;
use App\Filament\Pages\MarketsBoard;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\Scopes\SiteScope;

/**
 * The read-model behind the operator **Markets** workspace — one tenant's targetable geo subjects, with
 * the context that says why each matters and whether it needs attention. UI-agnostic and testable; the
 * Filament page ({@see MarketsBoard}) is thin over it and routes the advisory-hold
 * control through {@see MarketHold}.
 *
 * A market row carries: tier ({@see MarketTier} — Priority vs Coverage), coverage flag, demographics
 * (population + neighborhood count), the downstream targeting weight (location pages pinned via
 * `Content.market_id`, keywords pinned via `Keyword.market_id`), and the advisory hold state
 * (`on_hold` / `release_at` / overdue). Rows are **most-urgent-first**: an overdue hold, then Priority
 * tier, then name — the same "attention first" order the operator lobby uses.
 */
class MarketPortfolio
{
    /**
     * @return array{
     *     markets: list<array{id: string, name: string, region: ?string, tier: MarketTier, tier_label: string, is_covered: bool, population: int, neighborhoods: int, pages: int, keywords: int, on_hold: bool, release_at: ?string, overdue: bool}>,
     *     summary: array{total: int, priority: int, covered: int, held: int, overdue: int}
     * }
     */
    public function for(?string $siteId): array
    {
        if ($siteId === null) {
            return ['markets' => [], 'summary' => ['total' => 0, 'priority' => 0, 'covered' => 0, 'held' => 0, 'overdue' => 0]];
        }

        $markets = Market::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)
            ->get();

        // Downstream targeting weight, one grouped query each (a market with no pins simply reads 0).
        $pageCounts = Content::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->whereNotNull('market_id')
            ->selectRaw('market_id, count(*) as c')->groupBy('market_id')
            ->pluck('c', 'market_id');

        $keywordCounts = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $siteId)->whereNotNull('market_id')
            ->selectRaw('market_id, count(*) as c')->groupBy('market_id')
            ->pluck('c', 'market_id');

        $rows = $markets->map(function (Market $m) use ($pageCounts, $keywordCounts): array {
            $demographics = is_array($m->demographics) ? $m->demographics : [];
            $neighborhoods = is_array($m->neighborhoods) ? $m->neighborhoods : [];

            return [
                'id' => (string) $m->id,
                'name' => (string) $m->name,
                'region' => $m->region !== null ? (string) $m->region : null,
                'tier' => $m->tier,
                'tier_label' => $m->tier === MarketTier::Priority ? 'Priority' : 'Coverage',
                'is_covered' => (bool) $m->is_covered,
                'population' => (int) ($demographics['population'] ?? 0),
                'neighborhoods' => count($neighborhoods),
                'pages' => (int) ($pageCounts[$m->id] ?? 0),
                'keywords' => (int) ($keywordCounts[$m->id] ?? 0),
                'on_hold' => (bool) $m->on_hold,
                'release_at' => $m->release_at?->toDateString(),
                'overdue' => $m->releaseOverdue(),
            ];
        })->all();

        // Most-urgent-first: overdue holds, then Priority tier (both descending — "true first"), then
        // name ascending. The descending keys put b on the left, the ascending name puts a on the left.
        usort($rows, function (array $a, array $b): int {
            return [$b['overdue'], $b['tier'] === MarketTier::Priority, mb_strtolower($a['name'])]
                <=> [$a['overdue'], $a['tier'] === MarketTier::Priority, mb_strtolower($b['name'])];
        });

        return [
            'markets' => $rows,
            'summary' => [
                'total' => count($rows),
                'priority' => count(array_filter($rows, fn (array $r): bool => $r['tier'] === MarketTier::Priority)),
                'covered' => count(array_filter($rows, fn (array $r): bool => $r['is_covered'])),
                'held' => count(array_filter($rows, fn (array $r): bool => $r['on_hold'])),
                'overdue' => count(array_filter($rows, fn (array $r): bool => $r['overdue'])),
            ],
        ];
    }
}
