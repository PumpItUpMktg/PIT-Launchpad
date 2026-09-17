<?php

namespace App\Filament\Pages\Operate;

use App\Locations\TownLocationAssigner;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use Filament\Notifications\Notification;

/**
 * Operate · Location pages — town + city-service pages grouped under their physical location,
 * full lifecycle. Carries the Live board's orphan-assignment controls (parent_location_id only —
 * never the composeLocation pin).
 */
class OperateLocationPages extends OperatePagesBoard
{
    protected static ?string $slug = 'operate/pages/locations';

    protected static ?string $navigationLabel = 'Location pages';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.operate.pages-board';

    protected function family(): string
    {
        return 'locations';
    }

    /** Assign an orphan town page to the location that serves it (grouping pin only). */
    public function assignLocation(string $contentId, string $locationId): void
    {
        $content = $this->ownedPage($contentId);
        if ($content === null || $locationId === '') {
            return;
        }

        $owned = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->whereKey($locationId)
            ->exists();
        if (! $owned) {
            return;
        }

        $content->forceFill(['parent_location_id' => $locationId])->save();
        Notification::make()->success()->title('Assigned.')->send();
    }

    /** Re-run the served-towns auto-assigner over this site's town pages. */
    public function reassign(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $result = app(TownLocationAssigner::class)->assign($site);
        Notification::make()->success()
            ->title("{$result['assigned']} town page(s) assigned")
            ->body($result['unmatched'] !== [] ? 'Unmatched: '.implode(', ', $result['unmatched']) : 'Every town matched a location.')
            ->send();
    }
}
