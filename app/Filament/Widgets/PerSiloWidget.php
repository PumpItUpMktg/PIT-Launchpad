<?php

namespace App\Filament\Widgets;

use App\Operator\PipelineMetrics;
use Filament\Widgets\ChartWidget;

/**
 * Content volume per silo — which silos produce, which are starved.
 */
class PerSiloWidget extends ChartWidget
{
    protected static ?int $sort = -1;

    protected ?string $heading = 'Content per silo';

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $rows = app(PipelineMetrics::class)->perSilo();

        return [
            'datasets' => [['label' => 'Content', 'data' => array_map(fn (array $r) => $r['total'], $rows)]],
            'labels' => array_map(fn (array $r) => $r['silo_name'], $rows),
        ];
    }
}
