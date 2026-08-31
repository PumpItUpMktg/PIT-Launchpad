<?php

namespace App\Filament\Pages\Citations;

use App\Citations\CitationDiagnostics;
use App\Citations\NapHydrationResult;
use App\Citations\NapProfileHydrator;
use App\Citations\Ui\LocationCitationCard;
use App\Citations\Ui\TenantCitationBoard;
use App\Jobs\RunCitationScan;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use App\Support\CurrentSite;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

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
    protected static bool $shouldRegisterNavigation = false; // reached from the portfolio index, not the sidebar

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $slug = 'citations/tenant';

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

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('diagnoseScan')
                ->label('Run scan diagnostics')
                ->icon('heroicon-o-wrench-screwdriver')
                ->color('gray')
                ->action(function (): void {
                    $site = $this->getSite();
                    if ($site === null) {
                        return;
                    }
                    CurrentSite::set($site->id);

                    $profiledIds = LocationNapProfile::query()->pluck('location_id')->all();
                    $location = Location::query()->whereIn('id', $profiledIds)->first()
                        ?? Location::query()->first();

                    if ($location === null) {
                        Notification::make()->warning()->title('No locations to diagnose')
                            ->body('Add a location (with a GBP) to this tenant first.')->send();

                        return;
                    }

                    $report = app(CitationDiagnostics::class)->forLocation($location);

                    $body = new HtmlString(
                        implode('<br>', array_map(fn (string $l): string => e($l), $report->lines()))
                        .'<br><br><strong>Likely cause:</strong> '.e($report->likelyCause())
                    );

                    $note = Notification::make()->title("Scan diagnostics — {$report->locationName}")->body($body)->persistent();
                    match ($report->severity()) {
                        'danger' => $note->danger(),
                        'warning' => $note->warning(),
                        default => $note->success(),
                    };
                    $note->send();
                }),
        ];
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

    /**
     * Queue a scan for one location. The scan compares directories against the canonical NAP, so if the location
     * doesn't have one yet we build it from its GBP first (the GBP is the source of truth) rather than blocking.
     * Only a location with no usable GBP data — nothing to derive a NAP from — is refused.
     */
    public function launchScan(string $locationId): void
    {
        $location = Location::query()->withoutGlobalScope(SiteScope::class)->find($locationId);
        if ($location === null) {
            return;
        }

        $result = $this->ensureNap($location);
        if ($result !== null && ! $result->created()) {
            Notification::make()->warning()->title('Can’t scan yet — no canonical NAP')
                ->body($this->missingNapBody($result))
                ->send();

            return;
        }

        if ($result?->created()) {
            Notification::make()->success()->title('Built the NAP from Google')
                ->body('Created the canonical NAP from this location’s GBP — scanning against it now.')->send();
        }

        RunCitationScan::dispatch($locationId, trigger: 'manual');
        Notification::make()->success()->title('Citation scan queued')->send();
    }

    /**
     * Fan out a scan for every location in the tenant, building a NAP from the GBP for any that lack one.
     * Locations with no usable GBP data are counted as skipped.
     */
    public function scanAll(): void
    {
        $site = $this->getSite();
        if ($site === null) {
            return;
        }

        $queued = 0;
        $skipped = 0;
        foreach ($this->board as $card) {
            $location = Location::query()->withoutGlobalScope(SiteScope::class)->find($card->locationId);
            if ($location === null) {
                continue;
            }

            $result = $this->ensureNap($location);
            if ($result !== null && ! $result->created()) {
                $skipped++;

                continue;
            }

            RunCitationScan::dispatch($card->locationId, trigger: 'manual');
            $queued++;
        }

        $note = Notification::make()->success()->title("Queued {$queued} scan(s)");
        if ($skipped > 0) {
            $note->body("{$skipped} skipped — no GBP data to build a NAP from.");
        }
        $note->send();
    }

    /**
     * Ensure the location has a canonical NAP, deriving one from its GBP when missing. Returns null when a NAP
     * already existed, or the hydration result when we attempted to create one (created / skipped-missing).
     */
    private function ensureNap(Location $location): ?NapHydrationResult
    {
        $exists = LocationNapProfile::query()->withoutGlobalScope(SiteScope::class)
            ->where('location_id', $location->id)->exists();
        if ($exists) {
            return null;
        }

        return app(NapProfileHydrator::class)->hydrate($location);
    }

    private function missingNapBody(NapHydrationResult $result): string
    {
        if ($result->skipped() && $result->missing !== []) {
            return 'Google is missing '.implode(', ', $result->missing).
                '. Import a Google Business Profile onto this location, or add a NAP profile manually.';
        }

        return 'Import a Google Business Profile onto this location, or add a NAP profile, so there’s a canonical listing to scan against.';
    }
}
