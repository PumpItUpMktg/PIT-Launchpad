<?php

namespace App\Filament\Widgets;

use App\Operator\PipelineMetrics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * App-level job health: render success/failure and publish failures. The failed
 * content surfaces in the §6c review queue (render_failed / publish_failed
 * statuses) where the §2 reset/requeue action lives.
 */
class JobHealthWidget extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $health = app(PipelineMetrics::class)->jobHealth();

        return [
            Stat::make('Renders succeeded', $health['render_succeeded']),
            Stat::make('Renders failed', $health['render_failed'])->color('danger'),
            Stat::make('Publishes failed', $health['publish_failed'])->color('danger'),
        ];
    }
}
