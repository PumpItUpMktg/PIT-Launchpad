<?php

namespace App\Filament\Client\Widgets;

use App\Client\ClientContext;
use App\Models\TownRankScan;
use App\TownRank\TownVisibility;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Town Visibility on the client dashboard (§7c): how much of the service territory can see the site in
 * search, 0–100, with the movement against the two-scan baseline and the weekly trend. TOWN-SEARCH mode only
 * ("{service} {town}", what a customer types): the from-town mode competes a bare term against national
 * sites and reads as failure when it is the wrong yardstick — it stays on the operator board. Observed
 * rankings only — no traffic or revenue claim. Hidden until the site has a finished town-rank scan.
 */
class TownVisibilityWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -3;

    /** @return array<int, Stat> */
    protected function getStats(): array
    {
        $site = app(ClientContext::class)->site();
        if ($site === null) {
            return [];
        }
        $visibility = app(TownVisibility::class)->forSite($site);
        $stats = [];
        foreach ([TownRankScan::MODE_TOWN_QUERY => 'Town visibility'] as $mode => $label) {
            $v = $visibility[$mode];
            if ($v['score'] === null) {
                continue;
            }
            $stat = Stat::make($label, $v['score'].' / 100')
                ->description(self::description($v))
                ->descriptionIcon($v['delta'] === null ? 'heroicon-m-minus' : ($v['delta'] > 0 ? 'heroicon-m-arrow-trending-up' : ($v['delta'] < 0 ? 'heroicon-m-arrow-trending-down' : 'heroicon-m-minus')))
                ->color($v['delta'] === null || $v['delta'] === 0 ? 'gray' : ($v['delta'] > 0 ? 'success' : 'danger'));
            if (count($v['history']) >= 2) {
                $stat->chart(array_map(fn (array $h): float => (float) $h['score'], $v['history']));
            }
            $stats[] = $stat;
        }

        return $stats;
    }

    /** @param array{score: int|null, previous: int|null, delta: int|null, baseline_scans: int, keywords: int, towns: int, page1_towns: int, top3_towns: int, history: list<array{date: string, score: int}>} $v */
    private static function description(array $v): string
    {
        $against = $v['baseline_scans'] >= 2 ? 'vs the last two scans' : 'since the last scan';
        $move = match (true) {
            $v['delta'] === null => 'first measurement',
            $v['delta'] > 0 => "up {$v['delta']} {$against}",
            $v['delta'] < 0 => 'down '.abs($v['delta']).' '.$against,
            default => "unchanged {$against}",
        };

        return sprintf('%s · page 1 in %s town-keyword pairs across %s keywords', $move, number_format($v['page1_towns']), number_format($v['keywords']));
    }
}
