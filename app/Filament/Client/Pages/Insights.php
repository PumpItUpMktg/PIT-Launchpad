<?php

namespace App\Filament\Client\Pages;

use App\Filament\Client\Widgets\LeadsHeadlineWidget;
use App\Filament\Client\Widgets\LocalGridWidget;
use App\Filament\Client\Widgets\PerformanceCardsWidget;
use App\Filament\Client\Widgets\PositionTrendWidget;
use App\Filament\Client\Widgets\ProgressWidget;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Widgets\Widget;

/**
 * The secondary "Insights" tab (§ Client Dashboard v1, PR 6b) that keeps the existing §7c widgets — leads
 * headline, position trends, local grid, performance cards, progress — reachable now that
 * {@see PerformanceOverview} is the spine-backed landing. Nothing was removed from §7c; it just moved off
 * the home. These widgets read live from their source tables (leads/GA4 etc.), distinct from the v1 spine.
 */
class Insights extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?string $navigationLabel = 'Insights';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.client.pages.insights';

    public function getTitle(): string
    {
        return 'Insights';
    }

    /**
     * @return array<int, class-string<Widget>>
     */
    public function getHeaderWidgets(): array
    {
        return [
            LeadsHeadlineWidget::class,
            PositionTrendWidget::class,
            LocalGridWidget::class,
            PerformanceCardsWidget::class,
            ProgressWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 2;
    }
}
