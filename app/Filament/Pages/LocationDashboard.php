<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Operate\LocationDashboard as LocationDashboardReader;
use App\Operator\ActiveTenant;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Per-Location Dashboard (operator) — one GBP-backed location's whole cluster on a single screen: GSC
 * performance, page inventory, indexing, keyword movement, a geo-grid summary (deep-linking to the PR 6
 * small-multiples board), reviews, and job-capture proof. Every module is an EXISTING v1 pipeline filtered
 * to the location's cluster — assembly, not new ingest — delegated to the testable {@see LocationDashboardReader}.
 *
 * Operator-only, internal test build (§ Geo Grid PR 7). siteId/locationId are URL-bound for deep-linking.
 *
 * @property-read array<string, string> $sites
 * @property-read array<string, string> $locations
 * @property-read array<string, mixed>|null $dashboard
 * @property-read Location|null $location
 */
class LocationDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Location Dashboard';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'location-dashboard';

    protected string $view = 'filament.pages.location-dashboard';

    // NOT #[Url]: the working tenant is the lock ({@see ActiveTenant}), never a URL param. mount() sets this
    // from the lock on every request; a URL-bound siteId was the shape-B lock-override pattern (tenant-lock).
    public ?string $siteId = null;

    #[Url]
    public ?string $locationId = null;

    /** Internal test surface — its final IA placement is still open. */
    public static function menuTag(): string
    {
        return 'unaddressed';
    }

    /** Operator-only: internal, uncalibrated test build. */
    public static function canAccess(): bool
    {
        return Auth::user()?->role === UserRole::Operator;
    }

    public function mount(): void
    {
        $this->siteId = app(ActiveTenant::class)->id();
        if ($this->locationId === null) {
            $this->locationId = array_key_first($this->locations);
        }
    }

    /**
     * The tenant's GBP-backed locations (the dashboard-eligible set — non-visitable bases get no dashboard).
     *
     * @return array<string, string>
     */
    public function getLocationsProperty(): array
    {
        if ($this->siteId === null) {
            return [];
        }

        return Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)->gbpBacked()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->map(fn ($name, $id): string => (string) ($name ?: $id))
            ->all();
    }

    public function getLocationProperty(): ?Location
    {
        if ($this->locationId === null) {
            return null;
        }

        return Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->whereKey($this->locationId)
            ->first();
    }

    /** @return array<string, mixed>|null */
    public function getDashboardProperty(): ?array
    {
        $location = $this->location;

        return $location !== null ? app(LocationDashboardReader::class)->for($location) : null;
    }

    /** Deep-link to the geo-grid small-multiples board for the current location. */
    public function geoGridUrl(): string
    {
        // No ?siteId= — LocationGeoGrid resolves the working tenant from the lock; only the in-tenant
        // locationId deep-link is carried.
        return LocationGeoGrid::getUrl(['locationId' => $this->locationId]);
    }
}
