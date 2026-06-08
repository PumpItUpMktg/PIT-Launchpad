<?php

namespace App\Filament\Client\Widgets;

use App\Client\ClientContext;
use App\Client\PerformanceCards;
use Filament\Widgets\Widget;

/**
 * The performance face of the lifecycle card: one card per published page with
 * its best position, refresh history, and publish date.
 */
class PerformanceCardsWidget extends Widget
{
    protected static ?int $sort = 2;

    protected string $view = 'filament.client.widgets.performance-cards';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $site = app(ClientContext::class)->site();

        return ['cards' => $site !== null ? app(PerformanceCards::class)->cards($site) : []];
    }
}
