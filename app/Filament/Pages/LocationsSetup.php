<?php

namespace App\Filament\Pages;

use App\Locations\Concerns\ManagesLocationCoverage;
use App\Operator\ActiveTenant;
use BackedEnum;
use Filament\Pages\Page;

/**
 * Locations (Settings) — the post-setup locations editor, and the "Service area" tab of the Towns
 * surface (Relay 3 · PR 5g). The whole workspace (base locations, counties served, tiered towns,
 * coverage map) lives in {@see ManagesLocationCoverage}, shared verbatim with the guided
 * WhereYouWork step.
 *
 * Tenancy (5g): this reads the LOCKED {@see ActiveTenant} like every other console surface — the old
 * per-page cross-tenant site picker is gone (it contradicted the 2a-2/2c lock). All Towns tabs share
 * the one working tenant.
 */
class LocationsSetup extends Page
{
    use ManagesLocationCoverage;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationLabel = 'Service area';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    // Superseded by the Setup steps (which deep-link this as the drill-down) — leaves the
    // sidebar when the new Setup menu is on; the route stays.
    public static function shouldRegisterNavigation(): bool
    {
        return ! config('launchpad.new_setup_enabled');
    }

    /** Menu-map family tag: setup-world editor (deep-linked from the new Setup steps). */
    public static function menuTag(): string
    {
        return 'setup';
    }

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.locations-setup';

    public ?string $siteId = null;

    public function mount(): void
    {
        // The locked working tenant (Lobby / topbar), shared with every Towns tab — no per-page picker.
        $this->siteId = app(ActiveTenant::class)->id();
        $this->enterCoverageWorkspace();
    }

    /**
     * Re-initialise the workspace if the working tenant changes under the component (the operator
     * relocks a different tenant). There is no in-page site picker any more — the tenant comes from
     * {@see ActiveTenant} — this is just the reactive re-init hook.
     */
    public function updatedSiteId(): void
    {
        $this->reset(['manualLat', 'manualLng', 'computed', 'adding', 'addName', 'addAddress', 'addQuery', 'placeResults', 'activeTab']);
        $this->enterCoverageWorkspace();
    }
}
