<?php

namespace App\Filament\Client\Widgets;

use App\Client\ClientContext;
use App\Client\CoverageSummary;
use App\Client\QuickWins;
use App\Client\RankingGains;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * SEO progress + the body of work: ranking gains (moved up / newly ranked),
 * quick-wins landed (early momentum), and content published.
 */
class ProgressWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -2;

    protected function getStats(): array
    {
        $site = app(ClientContext::class)->site();
        if ($site === null) {
            return [];
        }

        $gains = app(RankingGains::class)->summary($site);
        $quickWins = count(app(QuickWins::class)->landed($site));
        $published = app(CoverageSummary::class)->publishedCount($site);

        return [
            Stat::make('Rankings improved', $gains['improved'])->color('success'),
            Stat::make('New rankings', $gains['new'])->color('success'),
            Stat::make('Quick-wins landed', $quickWins)->description('Early, low-difficulty keywords ranking'),
            Stat::make('Content published', $published),
        ];
    }
}
