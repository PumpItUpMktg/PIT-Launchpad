<?php

namespace App\Filament\Pages;

use App\Integrations\Census\Geocoder;
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
 * Locations setup (operator admin) — the missing radius/coverage surface between expand
 * and volume. It connects a site's base Location records (geocoded point + place from the
 * Places import) to a service RADIUS, then computes + persists the municipality coverage
 * union via the proven {@see LocationCoverage} engine. The page IS the UI form of
 * `launchpad:locations-coverage`: set each radius, hit Compute, and eyeball the real
 * NJ/eastern-PA towns + townships (cross-border where the radius reaches), deduped across
 * bases. silo-volume reads the persisted coverage from here.
 *
 * @property-read array<string, string> $siteOptions
 * @property-read Collection<int, Location> $locations
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

    /** @var array<string, int> locationId => radius miles */
    public array $radii = [];

    /** @var array<string, string> locationId => editable address */
    public array $addresses = [];

    /** @var array<string, string> locationId => manual lat entry */
    public array $manualLat = [];

    /** @var array<string, string> locationId => manual lng entry */
    public array $manualLng = [];

    public string $newName = '';

    public string $newAddress = '';

    /** @var array<string, mixed> the computed coverage (CoverageResult::toArray), empty until Compute */
    public array $coverage = [];

    public bool $computed = false;

    public function updatedSiteId(): void
    {
        $this->reset(['radii', 'addresses', 'manualLat', 'manualLng', 'coverage', 'computed']);
        $this->loadLocationState();
    }

    /** Geocode a base location from its (editable) address via the Census geocoder. */
    public function geocode(string $locationId): void
    {
        $location = $this->location($locationId);
        if ($location === null) {
            return;
        }

        $address = trim($this->addresses[$locationId] ?? (string) $location->address);
        if ($address === '') {
            Notification::make()->title('Enter an address to geocode.')->warning()->send();

            return;
        }

        $result = app(Geocoder::class)->geocode($address);
        if ($result === null) {
            Notification::make()->title('No match')->body("The Census geocoder couldn't resolve that address — check it, or paste coordinates manually.")->warning()->send();

            return;
        }

        $location->forceFill([
            'address' => $result->matchedAddress,
            'lat' => $result->lat,
            'lng' => $result->lng,
        ])->save();
        $this->addresses[$locationId] = $result->matchedAddress;

        Notification::make()->title('Geocoded')->body("{$result->matchedAddress} → {$result->lat}, {$result->lng}")->success()->send();
    }

    /** Manual fallback: store pasted coordinates directly. */
    public function saveManualPoint(string $locationId): void
    {
        $location = $this->location($locationId);
        if ($location === null) {
            return;
        }

        $lat = (float) ($this->manualLat[$locationId] ?? '');
        $lng = (float) ($this->manualLng[$locationId] ?? '');
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat === 0.0 && $lng === 0.0)) {
            Notification::make()->title('Enter a valid lat/lng.')->warning()->send();

            return;
        }

        $location->forceFill(['lat' => $lat, 'lng' => $lng])->save();
        $this->manualLat[$locationId] = '';
        $this->manualLng[$locationId] = '';

        Notification::make()->title('Point set')->body("{$lat}, {$lng}")->success()->send();
    }

    /** Add a base location (address → geocode) for a site that has none (or another). */
    public function addLocation(): void
    {
        $site = $this->siteId === null ? null : Site::query()->find($this->siteId);
        if ($site === null) {
            return;
        }

        $name = trim($this->newName);
        if ($name === '') {
            Notification::make()->title('Name the location first.')->warning()->send();

            return;
        }

        $address = trim($this->newAddress);
        $location = new Location;
        $location->forceFill([
            'site_id' => $site->id,
            'name' => $name,
            'address' => $address === '' ? null : $address,
        ])->save();

        if ($address !== '') {
            $this->addresses[$location->id] = $address;
            $this->geocode($location->id);
        }

        $this->reset(['newName', 'newAddress']);
        $this->loadLocationState();

        Notification::make()->title('Location added')->success()->send();
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

    public function compute(): void
    {
        $site = $this->siteId === null ? null : Site::query()->find($this->siteId);
        if ($site === null) {
            Notification::make()->title('Pick a site first.')->warning()->send();

            return;
        }

        // Persist the chosen radius on each base location (the engine reads it from there).
        foreach ($this->locations as $location) {
            $location->forceFill(['coverage_radius' => $this->radiusFor($location->id)])->save();
        }

        $result = app(LocationCoverage::class)->coverage($site);

        if ($result->perBase === []) {
            Notification::make()
                ->title('No computable base locations')
                ->body('Each base needs a geocoded point (lat/lng from the Places import) and a radius.')
                ->warning()
                ->send();

            return;
        }

        // Persist/cache the union as the authoritative CoverageArea set (the silo-volume dependency).
        $count = app(CoverageWriter::class)->write($site, $result);

        $this->coverage = $result->toArray();
        $this->computed = true;

        Notification::make()
            ->title('Coverage computed')
            ->body("{$count} municipalities across ".count($result->perBase).' base location(s) — saved.')
            ->success()
            ->send();
    }

    private function loadLocationState(): void
    {
        // Honor whatever is saved on the Location (the CLI --save writes the same field) —
        // single source of truth, no drift. Default only when unset.
        foreach ($this->locations as $location) {
            $this->radii[$location->id] = $location->coverage_radius ?? self::DEFAULT_RADIUS;
            $this->addresses[$location->id] = (string) $location->address;
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
}
