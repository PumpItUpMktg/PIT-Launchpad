<?php

namespace App\Filament\Widgets;

use App\Operator\PipelineMetrics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Portfolio-level pipeline stat cards. (Per-tenant figures use the same
 * PipelineMetrics service with a site id.)
 */
class PipelineStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -3;

    protected function getStats(): array
    {
        $m = app(PipelineMetrics::class)->statCards();

        return [
            Stat::make('Needs review', $m['needs_review']),
            Stat::make('Approved — pending publish', $m['approved_pending']),
            Stat::make('Published this week', $m['published_this_week']),
            Stat::make('Render failed', $m['render_failed'])->color('danger'),
            Stat::make('Publish failed', $m['publish_failed'])->color('danger'),
            Stat::make('Drafts in flight', $m['drafts_in_flight']),
        ];
    }
}
