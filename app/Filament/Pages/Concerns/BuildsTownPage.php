<?php

namespace App\Filament\Pages\Concerns;

use App\Build\TownPageBuilder;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * "Build this page" on a map's town panel, for the two maps that have one.
 *
 * A town with no page is exactly the town whose diagnosis says to build one, and the panel that says so is
 * the natural place to do it — rather than remembering the name and finding it on another board. Both the
 * Service Areas polygons and the Town Rank dots carry the same panel, so they carry the same action, from
 * the same {@see TownPageBuilder} the pages board uses: select, assemble, materialize that one town, queue
 * the draft.
 *
 * The host page supplies `$townId` (the selected `CoverageArea`) and `site()`.
 */
trait BuildsTownPage
{
    public function buildTownPage(): void
    {
        $site = $this->site();
        $town = $site === null || $this->townId === null ? null : CoverageArea::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $site->id)->whereKey($this->townId)->first();
        if ($site === null || $town === null) {
            return;
        }

        $result = app(TownPageBuilder::class)->build($site, $town, Auth::id());

        if ($result['page'] === null) {
            Notification::make()->warning()->title('Nothing to build')
                ->body('No plan entry could be made for this town.')->send();

            return;
        }

        Notification::make()->success()
            ->title($result['queued'] ? "Building a page for {$town->name}" : "{$town->name} added to the plan")
            ->body($result['queued']
                ? 'Queued on the worker. It appears under this location on Pages → Town as it drafts.'
                : 'The page is created but not ready to write yet — its details are still coming together.')
            ->send();
    }

    abstract private function site(): ?Site;
}
