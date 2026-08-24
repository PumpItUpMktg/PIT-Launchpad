<?php

namespace App\Filament\Widgets;

use App\Enums\UserRole;
use App\Geo\GeoContentSummary;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * The AI Content page header: the GEO growth loop's shipped body of work — published-per-silo tally
 * plus a candidates / in-review / published pipeline glance. Read-only over {@see GeoContentSummary}.
 */
class GeoContentSummaryWidget extends Widget
{
    protected string $view = 'filament.widgets.geo-content-summary';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return Auth::user()?->role === UserRole::Operator;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $summary = app(GeoContentSummary::class);

        return [
            'counts' => $summary->laneCounts(),
            'silos' => $summary->publishedBySilo(),
        ];
    }
}
