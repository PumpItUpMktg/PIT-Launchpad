<?php

namespace App\Filament\Widgets;

use App\Operator\PipelineMetrics;
use Filament\Widgets\ChartWidget;

/**
 * The pipeline funnel: how much content sits at each stage, candidate → published.
 */
class PipelineFunnelWidget extends ChartWidget
{
    protected static ?int $sort = -2;

    protected ?string $heading = 'Pipeline funnel';

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $funnel = app(PipelineMetrics::class)->funnel();

        return [
            'datasets' => [['label' => 'Content', 'data' => array_values($funnel)]],
            'labels' => array_map(fn (string $stage) => ucwords(str_replace('_', ' ', $stage)), array_keys($funnel)),
        ];
    }
}
