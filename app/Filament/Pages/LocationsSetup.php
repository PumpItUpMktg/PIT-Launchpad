<?php

namespace App\Filament\Pages;

use App\Integrations\Places\PlacesProvider;
use App\Jobs\GeocodeLocation;
use App\Locations\CoverageWriter;
use App\Locations\LocationCoverage;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Locations (operator admin) — the ONE locations surface per site. A list of base
 * locations (each: where it is + how far it serves + located status) and a single
 * "Add location" flow. Owner framing throughout — "where you are" / "how far you serve",
 * never "radius / lat/lng".
 *
 * Add is one path, no manual geo: source (from Google/GBP, or name + address) → the point
 * is geocoded in the BACKGROUND ({@see GeocodeLocation} via the keyless Census geocoder)
 * → the owner is asked "how far do you serve?" (the {@see LocationCoverage} radius) inline
 * → coverage computes across all bases. A manual lat/lng override surfaces ONLY when
 * geocoding fails. Existing un-located bases auto-geocode on open. GBP/Places gives the
 * point; the owner gives the reach (we never pull GBP's service-area list).
 *
 * @property-read array<string, string> $siteOptions
 * @property-read Collection<int, Location> $locations
 * @property-read bool $placesEnabled
 */
class LocationsSetup extends Page
{
    /** @var list<int> */
    public const RADII = [10, 15, 25];

    public const DEFAULT_RADIUS = 25;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationLabel = 'Locations';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.locations-setup';

    public ?string $siteId = null;

    /** @var array<string, int> locationId => "how far you serve" (miles) */
    public array $radii = [];

    /** @var array<string, string> locationId => manual lat override (failure only) */
    public array $manualLat = [];

    /** @var array<string, string> locationId => manual lng override (failure only) */
    public array $manualLng = [];

    // Add-location flow.
    public bool $adding = false;

    public string $addSource = 'manual';

    public string $addName = '';

    public string $addAddress = '';

    public int $addRadius = self::DEFAULT_RADIUS;

    public string $addQuery = '';

    /** @var list<array{place_id: string, name: string, address: string}> */
    public array $placeResults = [];

    /** @var array<string, mixed> the computed coverage (CoverageResult::toArray) */
    public array $coverage = [];

    public bool $computed = false;

    public function updatedSiteId(): void
    {
        $this->reset(['radii', 'manualLat', 'manualLng', 'coverage', 'computed', 'adding', 'addName', 'addAddress', 'addQuery', 'placeResults']);
        $this->addRadius = self::DEFAULT_RADIUS;
        $this->loadRadii();
        $this->autoGeocodePending();
    }

    public function startAdd(): void
    {
        $this->reset(['addName', 'addAddress', 'addQuery', 'placeResults']);
        $this->addRadius = self::DEFAULT_RADIUS;
        $this->addSource = $this->placesEnabled ? 'places' : 'manual';
        $this->adding = true;
    }

    public function cancelAdd(): void
    {
        $this->adding = false;
    }

    /** "From Google/GBP": search for the listing. */
    public function searchPlaces(): void
    {
        $this->placeResults = [];
        if (trim($this->addQuery) === '') {
            return;
        }

        foreach (app(PlacesProvider::class)->search($this->addQuery) as $candidate) {
            $this->placeResults[] = [
                'place_id' => $candidate->placeId,
                'name' => $candidate->name,
                'address' => $candidate->address,
            ];
        }

        if ($this->placeResults === []) {
            Notification::make()->title('No matches — try the address, or add it manually.')->warning()->send();
        }
    }

    /** Pick a listing → pulls name, address, AND coordinates (no geocode needed). */
    public function addFromPlace(string $placeId): void
    {
        $site = $this->site();
        if ($site === null) {
            return;
        }

        $details = app(PlacesProvider::class)->details($placeId);
        if ($details === null) {
            Notification::make()->title("Couldn't load that listing.")->warning()->send();

            return;
        }

        $location = new Location;
        $location->forceFill([
            'site_id' => $site->id,
            'name' => $details->name,
            'address' => $details->address,
            'place_id' => $details->placeId,
            'gbp_url' => $details->gbpUrl,
            'lat' => $details->lat,
            'lng' => $details->lng,
            'coverage_radius' => $this->addRadius,
            'geocode_failed' => $details->lat === null || $details->lng === null,
        ])->save();

        if ($location->lat === null) {
            GeocodeLocation::dispatch($location->id); // listing had no point — fall back to address geocode
        }

        $this->finishAdd("{$details->name} added.");
    }

    /** Manual: name + address → background geocode. */
    public function addManual(): void
    {
        $site = $this->site();
        if ($site === null) {
            return;
        }

        $name = trim($this->addName);
        if ($name === '') {
            Notification::make()->title('Give the location a name.')->warning()->send();

            return;
        }

        $address = trim($this->addAddress);
        $location = new Location;
        $location->forceFill([
            'site_id' => $site->id,
            'name' => $name,
            'address' => $address === '' ? null : $address,
            'coverage_radius' => $this->addRadius,
        ])->save();

        GeocodeLocation::dispatch($location->id); // located quietly in the background

        $this->finishAdd("{$name} added — locating it now.");
    }

    /** Manual override, surfaced only when background geocoding failed. */
    public function saveManualPoint(string $locationId): void
    {
        $location = $this->location($locationId);
        if ($location === null) {
            return;
        }

        $lat = (float) ($this->manualLat[$locationId] ?? '');
        $lng = (float) ($this->manualLng[$locationId] ?? '');
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat === 0.0 && $lng === 0.0)) {
            Notification::make()->title('Enter a valid location.')->warning()->send();

            return;
        }

        $location->forceFill(['lat' => $lat, 'lng' => $lng, 'geocode_failed' => false])->save();
        $this->manualLat[$locationId] = '';
        $this->manualLng[$locationId] = '';
        $this->compute();
    }

    public function compute(): void
    {
        $site = $this->site();
        if ($site === null) {
            return;
        }

        // Persist each location's "how far you serve" (the engine reads it from there).
        foreach ($this->locations as $location) {
            $location->forceFill(['coverage_radius' => $this->radiusFor($location->id)])->save();
        }

        $result = app(LocationCoverage::class)->coverage($site);
        if ($result->perBase === []) {
            Notification::make()->title('Nothing to map yet')->body('Add a location and let it locate first.')->warning()->send();

            return;
        }

        $count = app(CoverageWriter::class)->write($site, $result);
        $this->coverage = $result->toArray();
        $this->computed = true;

        Notification::make()->title('Service area updated')->body("{$count} towns across ".count($result->perBase).' location(s).')->success()->send();
    }

    /**
     * @return array<string, string>
     */
    public function getSiteOptionsProperty(): array
    {
        return Site::query()->orderBy('brand_name')->pluck('brand_name', 'id')->all();
    }

    /**
     * @return Collection<int, Location>
     */
    public function getLocationsProperty(): Collection
    {
        if ($this->siteId === null) {
            return collect();
        }

        return Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->orderBy('name')
            ->get();
    }

    public function getPlacesEnabledProperty(): bool
    {
        return (string) config('services.google.maps_api_key', '') !== '';
    }

    private function finishAdd(string $message): void
    {
        $this->adding = false;
        $this->reset(['addName', 'addAddress', 'addQuery', 'placeResults']);
        $this->addRadius = self::DEFAULT_RADIUS;
        $this->loadRadii();
        $this->compute(); // coverage refreshes immediately across all located bases

        Notification::make()->title($message)->success()->send();
    }

    private function autoGeocodePending(): void
    {
        // Background-locate any existing base that has an address but no point yet.
        foreach ($this->locations as $location) {
            if ($location->lat === null && ! $location->geocode_failed && trim((string) $location->address) !== '') {
                GeocodeLocation::dispatch($location->id);
            }
        }
    }

    private function loadRadii(): void
    {
        foreach ($this->locations as $location) {
            $this->radii[$location->id] = $location->coverage_radius ?? self::DEFAULT_RADIUS;
        }
    }

    private function location(string $locationId): ?Location
    {
        return $this->siteId === null ? null : Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->whereKey($locationId)
            ->first();
    }

    private function radiusFor(string $locationId): int
    {
        return (int) ($this->radii[$locationId] ?? self::DEFAULT_RADIUS);
    }

    private function site(): ?Site
    {
        return $this->siteId === null ? null : Site::query()->find($this->siteId);
    }
}
