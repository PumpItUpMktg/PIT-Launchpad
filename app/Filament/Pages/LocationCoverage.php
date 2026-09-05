<?php

namespace App\Filament\Pages;

use App\Enums\UserRole;
use App\GeoGrid\CoverageMap;
use App\GeoGrid\GeoGridScanner;
use App\Models\GeoGridScan;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Operator\ActiveTenant;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Coverage Progress (operator) — the per-(location × service) town-visibility view: the latest coverage scan
 * rendered large as a town scatter map, a headline Local Visibility Score, and a filmstrip of prior scans so
 * progress reads at a glance. Reads the coverage-mode scans captured by {@see GeoGridScanner}
 * via the {@see CoverageMap} read-model. Operator-only, internal (like the sibling geo surfaces).
 *
 * @property-read array<string, string> $sites
 * @property-read array<string, string> $locations
 * @property-read array<string, mixed>|null $coverage
 * @property-read Location|null $location
 */
class LocationCoverage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationLabel = 'Coverage Progress';

    protected static string|\UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 11;

    protected static ?string $slug = 'coverage-progress';

    protected string $view = 'filament.pages.location-coverage';

    public ?string $siteId = null;

    #[Url]
    public ?string $locationId = null;

    #[Url]
    public ?string $keywordId = null;

    public static function menuTag(): string
    {
        return 'unaddressed';
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->role === UserRole::Operator;
    }

    public function mount(): void
    {
        $this->siteId = app(ActiveTenant::class)->id();
        $this->locationId ??= $this->firstCoverageLocationId() ?? array_key_first($this->locations);
    }

    public function updatedLocationId(): void
    {
        $this->keywordId = null;   // let the read-model pick the most-recent service for the new location
    }

    /** @return array<string, string> */

    /** @return array<string, string> */
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
    public function getCoverageProperty(): ?array
    {
        $location = $this->location;

        return $location !== null ? app(CoverageMap::class)->for($location, $this->keywordId) : null;
    }

    private function firstCoverageLocationId(): ?string
    {
        if ($this->siteId === null) {
            return null;
        }

        return GeoGridScan::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)->where('mode', 'coverage')
            ->orderByDesc('scanned_at')
            ->value('location_id');
    }
}
