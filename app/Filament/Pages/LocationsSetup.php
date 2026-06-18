<?php

namespace App\Filament\Pages;

use App\Enums\MunicipalityType;
use App\Integrations\Census\County;
use App\Integrations\Census\Municipality;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Integrations\Places\PlacesProvider;
use App\Jobs\GeocodeLocation;
use App\Locations\CountyCoverage;
use App\Locations\CoverageWriter;
use App\Locations\ManualCoverage;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * Locations (operator admin) — the ONE locations surface per site. A list of base
 * locations (each: where it is + which counties it serves + located status) and a single
 * "Add location" flow. Owner framing throughout — "where you are" / "counties you serve",
 * never "radius / lat/lng".
 *
 * Coverage is COUNTY-based: each base is geocoded for its map pin, the home county is
 * auto-resolved + default-selected, and the owner ticks the counties they serve. Coverage
 * is every county subdivision (town) in those counties, joined to ACS population for a
 * Large / Medium / Small split, unioned + GEOID-deduped across all bases.
 *
 * Add is one path, no manual geo: source (from Google/GBP, or name + address) → the point
 * is geocoded in the BACKGROUND ({@see GeocodeLocation}) → the home county is resolved and
 * pre-ticked → the owner adjusts counties inline → coverage computes across all bases. A
 * manual lat/lng override surfaces ONLY when geocoding fails.
 *
 * @property-read array<string, string> $siteOptions
 * @property-read Collection<int, Location> $locations
 * @property-read bool $placesEnabled
 * @property-read string|null $geocoderWarning
 * @property-read array<string, string> $colors
 * @property-read list<array{name: string, lat: float, lng: float, color: string}> $mapData
 * @property-read list<array{name: string, lat: float, lng: float}> $manualMarkers
 */
class LocationsSetup extends Page
{
    /** Card-swatch / map-pin colors, assigned per location by position. */
    public const PALETTE = ['#2563eb', '#16a34a', '#db2777', '#d97706', '#7c3aed', '#0891b2'];

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationLabel = 'Locations';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.locations-setup';

    public ?string $siteId = null;

    /** @var array<string, string> locationId => manual lat override (failure only) */
    public array $manualLat = [];

    /** @var array<string, string> locationId => manual lng override (failure only) */
    public array $manualLng = [];

    // Add-location flow.
    public bool $adding = false;

    public string $addSource = 'manual';

    public string $addName = '';

    public string $addAddress = '';

    public string $addQuery = '';

    /** @var list<array{place_id: string, name: string, address: string}> */
    public array $placeResults = [];

    /** @var array<string, mixed> the computed coverage (CoverageResult::toArray) */
    public array $coverage = [];

    public bool $computed = false;

    // "Add a town" (directed coverage) — per location card.
    /** @var array<string, string> locationId => town search query */
    public array $townQuery = [];

    /** @var array<string, list<array{geo_id: string, name: string, type: string, state: string|null, lat: float|null, lng: float|null}>> */
    public array $townResults = [];

    /** Per-request memo: stateFips => the state's counties (avoids re-querying on each render). */
    private array $countyOptionsCache = [];

    public function updatedSiteId(): void
    {
        $this->reset(['manualLat', 'manualLng', 'coverage', 'computed', 'adding', 'addName', 'addAddress', 'addQuery', 'placeResults']);
        $this->autoGeocodePending();
        $this->buildCoverage(notify: false); // show the map + reach immediately for located bases
    }

    public function startAdd(): void
    {
        $this->reset(['addName', 'addAddress', 'addQuery', 'placeResults']);
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
            'geocode_failed' => $details->lat === null || $details->lng === null,
        ])->save();

        // Resolve the home county for a point that came straight from the listing.
        GeocodeLocation::dispatch($location->id);

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
        ])->save();

        GeocodeLocation::dispatch($location->id); // located + home county resolved in the background

        $this->finishAdd("{$name} added — locating it now.");
    }

    /**
     * Re-attempt geocoding a base that previously failed — clears the failed flag and
     * re-dispatches the job, which runs the (Google-primary) geocoder. Lets an address
     * that failed under the old Census-only geocoder (e.g. unincorporated "Trooper, PA")
     * resolve via Google without re-adding the location.
     */
    public function retryGeocode(string $locationId): void
    {
        $location = $this->location($locationId);
        if ($location === null) {
            return;
        }

        $location->forceFill(['geocode_failed' => false])->save();
        GeocodeLocation::dispatch($location->id);
        $this->buildCoverage(notify: false);

        Notification::make()->title("Locating {$location->name}…")->send();
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

        // Resolve the home county for the hand-set point + default-select it.
        $county = app(MunicipalityGazetteer::class)->countyAt($lat, $lng);
        $selected = is_array($location->county_geoids) ? $location->county_geoids : [];
        if ($county !== null && $selected === []) {
            $selected = [$county->geoId];
        }

        $location->forceFill([
            'lat' => $lat,
            'lng' => $lng,
            'geocode_failed' => false,
            'home_county_geoid' => $county?->geoId,
            'county_geoids' => $selected,
        ])->save();
        $this->manualLat[$locationId] = '';
        $this->manualLng[$locationId] = '';
        $this->compute();
    }

    /**
     * Tick / untick a county this location serves — persists the selection and recomputes
     * coverage quietly. Default-selected counties (the home county) start ticked.
     */
    public function toggleCounty(string $locationId, string $countyGeoId): void
    {
        $location = $this->location($locationId);
        if ($location === null) {
            return;
        }

        $selected = is_array($location->county_geoids) ? $location->county_geoids : [];
        if (in_array($countyGeoId, $selected, true)) {
            $selected = array_values(array_filter($selected, fn ($g) => $g !== $countyGeoId));
        } else {
            $selected[] = $countyGeoId;
        }

        $location->forceFill(['county_geoids' => $selected])->save();
        $this->buildCoverage(notify: false);
    }

    public function compute(): void
    {
        $this->buildCoverage(notify: true);
    }

    /** Search municipalities by name for a directed "add a town". */
    public function searchTowns(string $locationId): void
    {
        $this->townResults[$locationId] = [];
        $query = trim($this->townQuery[$locationId] ?? '');
        if ($query === '') {
            return;
        }

        foreach (app(ManualCoverage::class)->search($query) as $m) {
            $this->townResults[$locationId][] = [
                'geo_id' => $m->geoId,
                'name' => $m->name,
                'type' => $m->type->value,
                'state' => $m->state,
                'lat' => $m->lat,
                'lng' => $m->lng,
            ];
        }

        if ($this->townResults[$locationId] === []) {
            Notification::make()->title('No towns matched that name.')->warning()->send();
        }
    }

    /** Add a searched town to this location's coverage (directed → priority page candidate). */
    public function addTown(string $locationId, string $geoId): void
    {
        $site = $this->site();
        $location = $this->location($locationId);
        if ($site === null || $location === null) {
            return;
        }

        $row = collect($this->townResults[$locationId] ?? [])->firstWhere('geo_id', $geoId);
        if ($row === null) {
            return;
        }

        app(ManualCoverage::class)->add($site, $location, new Municipality(
            geoId: $row['geo_id'],
            name: $row['name'],
            type: MunicipalityType::from($row['type']),
            state: $row['state'],
            lat: $row['lat'],
            lng: $row['lng'],
        ));

        $this->townQuery[$locationId] = '';
        $this->townResults[$locationId] = [];
        $this->buildCoverage(notify: false);

        Notification::make()->title("Added {$row['name']} — priority page candidate.")->success()->send();
    }

    public function removeTown(string $geoId): void
    {
        $site = $this->site();
        if ($site === null) {
            return;
        }

        app(ManualCoverage::class)->remove($site, $geoId);
        $this->buildCoverage(notify: false);
    }

    private function buildCoverage(bool $notify): void
    {
        $site = $this->site();
        if ($site === null) {
            return;
        }

        $result = app(CountyCoverage::class)->coverage($site);
        $this->dispatch('locations-updated', data: $this->mapData, manual: $this->manualMarkers);

        if ($result->perBase === []) {
            $this->coverage = [];
            $this->computed = false;
            if ($notify) {
                Notification::make()->title('Nothing to map yet')->body('Add a location and tick the counties it serves.')->warning()->send();
            }

            return;
        }

        $count = app(CoverageWriter::class)->write($site, $result);
        $this->coverage = $result->toArray();
        $this->computed = true;

        if ($notify) {
            Notification::make()->title('Service area updated')->body("{$count} towns across ".count($result->perBase).' location(s).')->success()->send();
        }
    }

    /**
     * The counties available for a location's multi-select — every county in the state its
     * geocoded point fell in (the home county's state). Empty until the base is located.
     *
     * @return list<array{geo_id: string, name: string}>
     */
    public function countyOptions(Location $location): array
    {
        $home = (string) $location->home_county_geoid;
        if (strlen($home) < 2) {
            return [];
        }

        $stateFips = substr($home, 0, 2);
        if (! isset($this->countyOptionsCache[$stateFips])) {
            $this->countyOptionsCache[$stateFips] = array_map(
                fn (County $c) => ['geo_id' => $c->geoId, 'name' => $c->name],
                app(MunicipalityGazetteer::class)->countiesInState($stateFips),
            );
        }

        return $this->countyOptionsCache[$stateFips];
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

    /**
     * Loud notice when geocoding will run on the Census fallback (no Google key) — Census
     * quietly misses unincorporated / edge addresses, so the operator should know.
     */
    public function getGeocoderWarningProperty(): ?string
    {
        return (string) config('services.google.maps_api_key', '') === ''
            ? 'Google Geocoding isn’t enabled — using the Census geocoder, which can miss unincorporated or edge addresses. Set GOOGLE_MAPS_API_KEY (Geocoding API enabled) for best results.'
            : null;
    }

    /**
     * Stable color per location (card swatch ↔ map pin), assigned by position.
     *
     * @return array<string, string>
     */
    public function getColorsProperty(): array
    {
        $colors = [];
        foreach ($this->locations->values() as $i => $location) {
            $colors[$location->id] = self::PALETTE[$i % count(self::PALETTE)];
        }

        return $colors;
    }

    /**
     * The located bases for the shared map: a colored pin per base (no circle — coverage is
     * county-based, not radial).
     *
     * @return list<array{name: string, lat: float, lng: float, color: string}>
     */
    public function getMapDataProperty(): array
    {
        $colors = $this->colors;
        $data = [];
        foreach ($this->locations as $location) {
            if ($location->lat !== null && $location->lng !== null) {
                $data[] = [
                    'name' => $location->name,
                    'lat' => (float) $location->lat,
                    'lng' => (float) $location->lng,
                    'color' => $colors[$location->id] ?? self::PALETTE[0],
                ];
            }
        }

        return $data;
    }

    /**
     * Manually-added (directed) towns for the map — distinct flag markers.
     *
     * @return list<array{name: string, lat: float, lng: float}>
     */
    public function getManualMarkersProperty(): array
    {
        $site = $this->site();
        if ($site === null) {
            return [];
        }

        $markers = [];
        foreach (app(ManualCoverage::class)->forSite($site) as $row) {
            if ($row->lat !== null && $row->lng !== null) {
                $markers[] = ['name' => $row->name, 'lat' => (float) $row->lat, 'lng' => (float) $row->lng];
            }
        }

        return $markers;
    }

    private function finishAdd(string $message): void
    {
        $this->adding = false;
        $this->reset(['addName', 'addAddress', 'addQuery', 'placeResults']);
        $this->buildCoverage(notify: false); // coverage refreshes immediately across all located bases

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

    private function location(string $locationId): ?Location
    {
        return $this->siteId === null ? null : Location::withoutGlobalScope(SiteScope::class)
            ->where('site_id', $this->siteId)
            ->whereKey($locationId)
            ->first();
    }

    private function site(): ?Site
    {
        return $this->siteId === null ? null : Site::query()->find($this->siteId);
    }
}
