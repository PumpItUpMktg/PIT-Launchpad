<?php

namespace App\Filament\Pages\Citations;

use App\Citations\Ui\LocationCitationCard;
use App\Citations\Ui\TenantCitationBoard;
use App\Jobs\RunCitationScan;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Support\CurrentSite;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Citations · Tenant board (§ Citations UI, PR B) — one card per physical location with its coverage broken
 * down (live / mismatch / submitted / missing) and its scan state. Its own top-level nav item (not in the
 * Operate group); operator-only via the panel gate. The working tenant is the shared session switcher, so it
 * moves in step with the rest of the admin. Cards launch a scan (per location, or all at once) on the worker.
 *
 * @property-read list<LocationCitationCard> $board
 * @property-read array<string, string> $siteOptions
 */
class CitationsBoard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationLabel = 'Citations';

    protected static ?string $slug = 'citations';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.citations.board';

    public ?string $siteId = null;

    /**
     * Menu-map bookkeeping (menu-reorg worksheet): the Citations surface is mid-build, so its final-menu
     * placement is a pending decision — inventories as `unaddressed`, not a legacy retire.
     */
    public static function menuTag(): string
    {
        return 'unaddressed';
    }

    public function mount(): void
    {
        $requested = request()->query('site');
        $candidate = is_string($requested) ? $requested : session('guided_site_id');

        $site = is_string($candidate) ? Site::query()->find($candidate) : null;
        $site ??= Site::query()->orderBy('brand_name')->first();

        if ($site !== null) {
            session(['guided_site_id' => $site->id]);
            $this->siteId = $site->id;
        }
    }

    /** Switch the working tenant (session-persisted, shared with the rest of the admin). */
    public function setSite(string $siteId): void
    {
        if (Site::query()->whereKey($siteId)->exists()) {
            session(['guided_site_id' => $siteId]);
            $this->siteId = $siteId;
        }
    }

    public function getSite(): ?Site
    {
        return $this->siteId === null ? null : Site::query()->find($this->siteId);
    }

    /** @return array<string, string> */
    public function getSiteOptionsProperty(): array
    {
        return Site::query()->orderBy('brand_name')->pluck('brand_name', 'id')->all();
    }

    /** @return list<LocationCitationCard> */
    public function getBoardProperty(): array
    {
        $site = $this->getSite();
        if ($site === null) {
            return [];
        }
        CurrentSite::set($site->id);

        return app(TenantCitationBoard::class)->forSite($site);
    }

    /** Queue a scan for one location (only when it has a NAP profile to scan against). */
    public function launchScan(string $locationId): void
    {
        if (! LocationNapProfile::query()->where('location_id', $locationId)->exists()) {
            Notification::make()->warning()->title('Add a NAP profile first')
                ->body('Citations need a canonical NAP to scan against.')->send();

            return;
        }

        RunCitationScan::dispatch($locationId, trigger: 'manual');
        Notification::make()->success()->title('Citation scan queued')->send();
    }

    /** Fan out a scan for every NAP-profiled location in the tenant. */
    public function scanAll(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $queued = 0;
        foreach ($this->board as $card) {
            if ($card->hasNap) {
                RunCitationScan::dispatch($card->locationId, trigger: 'manual');
                $queued++;
            }
        }

        Notification::make()->success()->title("Queued {$queued} scan(s)")->send();
    }
}
