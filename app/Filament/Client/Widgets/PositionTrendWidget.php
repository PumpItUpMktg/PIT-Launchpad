<?php

namespace App\Filament\Client\Widgets;

use App\Client\ClientContext;
use App\Client\PositionTrends;
use App\Models\Keyword;
use App\Models\Scopes\SiteScope;
use Filament\Widgets\Widget;

/**
 * Organic position-over-time for the lead keyword, with RefreshEvent markers
 * overlaid as OBSERVED CORRELATION (content refreshed here, position moved
 * there) — never a causal/ROI claim.
 */
class PositionTrendWidget extends Widget
{
    protected static ?int $sort = 0;

    protected string $view = 'filament.client.widgets.position-trend';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $site = app(ClientContext::class)->site();
        if ($site === null) {
            return ['keyword' => null, 'trend' => null];
        }

        $keyword = Keyword::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->whereHas('positionSnapshots')
            ->first();

        return [
            'keyword' => $keyword,
            'trend' => $keyword !== null ? app(PositionTrends::class)->forKeyword($keyword) : null,
        ];
    }
}
