<?php

namespace App\Filament\Widgets;

use App\Operator\PipelineMetrics;
use Filament\Widgets\ChartWidget;

/**
 * Publishing cadence over the last eight weeks.
 */
class PublishedTrendWidget extends ChartWidget
{
    protected ?string $heading = 'Published / week';

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $weeks = app(PipelineMetrics::class)->publishedPerWeek();

        return [
            'datasets' => [['label' => 'Published', 'data' => array_values($weeks)]],
            'labels' => array_keys($weeks),
        ];
    }
}
