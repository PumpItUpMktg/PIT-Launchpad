<?php

namespace App\Filament\Widgets;

use App\Enums\GeoCheckAction;
use App\Enums\UserRole;
use App\Models\GeoCheckEvent;
use App\Models\Scopes\SiteScope;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Auth;

/**
 * The GEO check activity log on the AI Search screen — "what the engine is doing in the background." Shows
 * the latest run's steps newest-first (measured / skipped-fresh / deferred / error, with who we're cited
 * against), plus a per-run count summary. Polls, so it fills in live while a check runs. Reads the
 * append-only {@see GeoCheckEvent} log.
 */
class GeoCheckActivityWidget extends Widget
{
    protected string $view = 'filament.widgets.geo-check-activity';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '10s';

    public static function canView(): bool
    {
        return Auth::user()?->role === UserRole::Operator;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $latestRunId = GeoCheckEvent::withoutGlobalScope(SiteScope::class)->latest('created_at')->value('run_id');
        if ($latestRunId === null) {
            return ['events' => [], 'counts' => null];
        }

        $counts = GeoCheckEvent::withoutGlobalScope(SiteScope::class)
            ->where('run_id', $latestRunId)
            ->selectRaw('action, count(*) as aggregate')->groupBy('action')->pluck('aggregate', 'action');

        $rows = GeoCheckEvent::withoutGlobalScope(SiteScope::class)
            ->where('run_id', $latestRunId)
            ->latest('created_at')->limit(25)->with('prompt:id,prompt')->get();

        return [
            'counts' => [
                'measured' => (int) ($counts[GeoCheckAction::Measured->value] ?? 0),
                'skipped_fresh' => (int) ($counts[GeoCheckAction::SkippedFresh->value] ?? 0),
                'deferred' => (int) ($counts[GeoCheckAction::Deferred->value] ?? 0),
                'error' => (int) ($counts[GeoCheckAction::Error->value] ?? 0),
            ],
            'events' => $rows->map(fn (GeoCheckEvent $e): array => [
                'town' => $e->town,
                'engine' => $e->engine,
                'label' => $e->action->label(),
                'color' => $e->action->color(),
                'is_measured' => $e->action === GeoCheckAction::Measured,
                'cited' => $e->cited,
                'competitors' => $e->competitors ?? [],
                'prompt' => data_get($e->prompt, 'prompt'),
            ])->all(),
        ];
    }
}
