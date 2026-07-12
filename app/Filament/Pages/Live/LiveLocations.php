<?php

namespace App\Filament\Pages\Live;

use App\Guided\LiveBoardPage;
use App\Guided\LiveBoards;
use App\Locations\TownLocationAssigner;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use Filament\Notifications\Notification;

/**
 * LIVE · Locations — every published geo surface grouped by the physical location that owns it:
 * the location landing page leading, the towns roll-up, the assigned town pages, and the earned
 * city-service pages. Orphan town pages surface with the assign-location picker (the served-towns
 * assigner fills most of them; the picker is the exception path).
 */
class LiveLocations extends LiveBoardPage
{
    protected static ?string $navigationLabel = 'Locations';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.live.locations';

    public function getTitle(): string
    {
        return 'Live · Locations';
    }

    /** @return array<string, mixed> */
    public function getBoardProperty(): array
    {
        $site = $this->getSite();

        return $site === null
            ? ['groups' => [], 'orphans' => [], 'location_options' => []]
            : app(LiveBoards::class)->locations($site);
    }

    /** @return array{serp: bool, gsc: bool, ga: bool} */
    public function getSourcesProperty(): array
    {
        $site = $this->getSite();

        return $site === null ? ['serp' => false, 'gsc' => false, 'ga' => false] : app(LiveBoards::class)->sources($site);
    }

    /** Assign an orphan town page to a location — the manual exception path beside the assigner. */
    public function assignLocation(string $contentId, string $locationId): void
    {
        $site = $this->getSite();
        $content = $this->ownedPage($contentId);
        if ($site === null || $content === null || $locationId === '') {
            return;
        }

        $location = Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)
            ->find($locationId);
        if ($location === null) {
            return;
        }

        $content->forceFill(['parent_location_id' => $location->id])->save();

        Notification::make()->success()->title('Assigned')
            ->body("'{$content->title}' now groups under {$location->name}.")->send();
    }

    /** Re-run the served-towns assigner for the site (after editing served towns). */
    public function reassign(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $result = app(TownLocationAssigner::class)->assign($site);
        Notification::make()->success()->title('Re-assigned')
            ->body($result['assigned'].' page(s) assigned'.($result['unmatched'] === [] ? '.' : ' · still unassigned: '.implode(', ', $result['unmatched'])))
            ->send();
    }
}
