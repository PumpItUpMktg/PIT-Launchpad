<?php

namespace App\Filament\Widgets;

use App\Enums\UserRole;
use App\Geo\GeoCheckStatus;
use App\Integrations\AiSearch\AiEngineRegistry;
use App\Models\GeoPrompt;
use App\Models\GeoSnapshot;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * The in-process indicator for the AI Search screen: while a GEO check is running for any tenant, this
 * shows a live "checking… N/M measured" banner (polling every few seconds) that disappears when the run
 * finishes. Reads the {@see GeoCheckStatus} flag a run sets; progress is the snapshots written since the
 * run started, over the (active prompts × enabled engines) pairs it will cover.
 */
class GeoCheckStatusWidget extends Widget
{
    protected string $view = 'filament.widgets.geo-check-status';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '5s';

    public static function canView(): bool
    {
        return Auth::user()?->role === UserRole::Operator;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $status = app(GeoCheckStatus::class);
        $engines = max(1, count(app(AiEngineRegistry::class)->enabled()));

        $siteIds = GeoPrompt::query()->withoutGlobalScope(SiteScope::class)
            ->where('active', true)->distinct()->pluck('site_id');

        $running = [];
        foreach ($siteIds as $siteId) {
            $startedAt = $status->startedAt((string) $siteId);
            if ($startedAt === null) {
                continue;
            }

            $prompts = GeoPrompt::query()->withoutGlobalScope(SiteScope::class)
                ->where('site_id', $siteId)->where('active', true)->count();
            $total = $prompts * $engines;
            $measured = GeoSnapshot::query()->withoutGlobalScope(SiteScope::class)
                ->where('site_id', $siteId)->where('checked_at', '>=', $startedAt)->count();
            $site = Site::query()->withoutGlobalScope(SiteScope::class)->whereKey($siteId)->first();

            $running[] = [
                'tenant' => (string) ($site?->brand_name ?: $siteId),
                'measured' => min($measured, $total),
                'total' => $total,
            ];
        }

        return ['running' => $running];
    }
}
