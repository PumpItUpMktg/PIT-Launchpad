<?php

namespace App\Filament\Client\Widgets;

use App\Client\ClientContext;
use App\Client\LocalGrid;
use Filament\Widgets\Widget;

/**
 * The per-market local-pack visibility heatmap — the client's local search
 * footprint across their geo grid.
 */
class LocalGridWidget extends Widget
{
    protected static ?int $sort = 1;

    protected string $view = 'filament.client.widgets.local-grid';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $site = app(ClientContext::class)->site();

        return ['markets' => $site !== null ? app(LocalGrid::class)->heatmap($site) : []];
    }
}
