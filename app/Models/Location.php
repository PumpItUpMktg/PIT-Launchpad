<?php

namespace App\Models;

use App\GeoGrid\GeoGridGeometry;
use App\Models\Concerns\BelongsToSite;
use App\Models\Scopes\ActiveLocationScope;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string|null $merged_into_id the NAP-reconcile survivor this row was folded into (hidden when set)
 * @property array<string, mixed>|null $hours
 * @property array<int, array<string, mixed>>|null $address_components
 * @property string|null $gbp_url
 * @property string|null $website the business website (from the GBP/Places import); mirrored into the NAP
 * @property string|null $phone
 * @property float|null $lat
 * @property float|null $lng
 * @property int|null $coverage_radius service radius in miles (preset {10,15,25}) for the Locations coverage engine
 * @property bool $geocode_failed background geocoding couldn't resolve the address — surface a manual override
 * @property string|null $home_county_geoid 5-digit county FIPS the geocoded point falls in
 * @property list<string>|null $county_geoids owner-selected counties served (5-digit GEOIDs)
 * @property string|null $address
 * @property string|null $place_id GBP/Places identifier — the hard key geo-grid ranks are matched by
 * @property float|null $grid_spacing_miles per-location geo-grid point spacing (miles); null → derived from config geo_grid.radius_miles (see {@see Location::gridSpacingMiles()})
 * @property array<int, array<string, mixed>>|null $served_towns GBP service-area towns {name, state, lat, lng, geocoded} — one location owns a town per site
 * @property string|null $market_notes operator free-text market context, fed VERBATIM to the location-page drafter
 * @property array<string, mixed>|null $grounding_cache cached local facts {facts, sources, fetched_at} (90-day staleness)
 * @property array<string, mixed>|null $coverage_suggestions extraction prompts (gathering relay): {towns: list<string> conflicting candidates, phrases: list<string> unresolved coverage phrases}
 * @property string|null $primary_category the GBP primary category label
 */
#[ScopedBy(ActiveLocationScope::class)]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /**
     * The single creation seam for the publish-hold default (location-integrity relay). EVERY production
     * path that creates a Location (GBP bulk import, Places import, manual Towns add, the Filament create
     * form, onboarding intake) goes through Eloquent, so defaulting `publish_held = true` here — rather than
     * at each call site — guarantees a newly created location can't publish until reviewed, with no route
     * able to miss it (the tenant-lock-writer lesson: a rule enforced at one entry point isn't enforced if
     * there are five). A caller that has genuinely reviewed the location may pass `publish_held` explicitly
     * to opt out; the test factory does exactly that (fixtures are publishable by default).
     */
    protected static function booted(): void
    {
        static::creating(function (Location $location): void {
            if (! array_key_exists('publish_held', $location->getAttributes())) {
                $location->publish_held = true;
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'hours' => 'array',
            'address_components' => 'array',
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'is_storefront' => 'boolean',
            'publish_held' => 'boolean',
            'geocode_failed' => 'boolean',
            'county_geoids' => 'array',
            'served_towns' => 'array',
            'grounding_cache' => 'array',
            'coverage_suggestions' => 'array',
            'grid_spacing_miles' => 'decimal:2',
        ];
    }

    /**
     * GBP-BACKED locations only: those with an attached Google Business Profile (a `gbp_url`), never a
     * NAP-reconcile row that was folded into another. The gate for the per-location dashboard + geo grid —
     * non-visitable bases (home, storage) with no listing get neither.
     *
     * @param  Builder<Location>  $query
     * @return Builder<Location>
     */
    public function scopeGbpBacked(Builder $query): Builder
    {
        return $query->whereNotNull('gbp_url')->whereNull('merged_into_id');
    }

    /** Whether this location has an attached GBP listing (dashboard-eligible). */
    public function isGbpBacked(): bool
    {
        return trim((string) $this->gbp_url) !== '' && $this->merged_into_id === null;
    }

    /**
     * Whether this location can be geo-grid scanned: GBP-backed AND carrying the hard prerequisites — the
     * `place_id` ranks are matched by (never business name) and the GBP `lat`/`lng` the grid centers on.
     */
    public function isGridReady(): bool
    {
        return $this->isGbpBacked()
            && trim((string) $this->place_id) !== ''
            && $this->lat !== null && $this->lng !== null;
    }

    /**
     * Scannable locations — GBP-backed with a place_id and a center coordinate. The set the scan command
     * iterates.
     *
     * @param  Builder<Location>  $query
     * @return Builder<Location>
     */
    public function scopeGridReady(Builder $query): Builder
    {
        return $this->scopeGbpBacked($query)
            ->whereNotNull('place_id')->whereNotNull('lat')->whereNotNull('lng');
    }

    /**
     * The grid point spacing (miles) to scan this location at — its per-location override, else the default
     * derived from the configured grid RADIUS (Local Falcon's knob): spacing = radius ÷ ((grid_size−1)/2).
     */
    public function gridSpacingMiles(): float
    {
        if ($this->grid_spacing_miles !== null) {
            return (float) $this->grid_spacing_miles;
        }

        return GeoGridGeometry::spacingForRadius(
            (float) config('launchpad.geo_grid.radius_miles', 10),
            (int) config('launchpad.geo_grid.grid_size', 7),
        );
    }

    /**
     * The location's own city + state, from the structured address components (locality +
     * administrative_area_level_1). Empty strings when not geocoded — callers degrade.
     *
     * @return array{city: string, state: string}
     */
    public function cityState(): array
    {
        $city = '';
        $state = '';
        foreach ($this->address_components ?? [] as $component) {
            $types = $component['types'] ?? [];
            if (in_array('locality', $types, true)) {
                $city = (string) ($component['long_name'] ?? '');
            }
            if (in_array('administrative_area_level_1', $types, true)) {
                $state = (string) ($component['short_name'] ?? '');
            }
        }

        return ['city' => $city, 'state' => $state];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
