<?php

namespace App\Filament\Client\Widgets;

use App\Client\ClientContext;
use App\Client\LeadsMetrics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The top-line: leads/conversions over the period (the revenue proxy) — a total
 * plus its weekly trend. Honest framing: totals and trend only, never attributed
 * to a specific action.
 */
class LeadsHeadlineWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -3;

    protected function getStats(): array
    {
        $site = app(ClientContext::class)->site();
        if ($site === null) {
            return [Stat::make('Leads', 0)];
        }

        $leads = app(LeadsMetrics::class);
        $trend = $leads->trend($site);

        return [
            Stat::make('Leads (90 days)', $leads->total($site))
                ->description('Total leads & conversions')
                ->chart(array_map('floatval', array_values($trend)))
                ->color('primary'),
        ];
    }
}
