<?php

namespace App\Models;

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
 * @property string|null $phone
 * @property float|null $lat
 * @property float|null $lng
 * @property int|null $coverage_radius service radius in miles (preset {10,15,25}) for the Locations coverage engine
 * @property bool $geocode_failed background geocoding couldn't resolve the address — surface a manual override
 * @property string|null $home_county_geoid 5-digit county FIPS the geocoded point falls in
 * @property list<string>|null $county_geoids owner-selected counties served (5-digit GEOIDs)
 * @property string|null $address
 * @property string|null $place_id GBP/Places identifier — the hard key geo-grid ranks are matched by
 * @property float|null $grid_spacing_miles per-location geo-grid point spacing (miles); null → {@see Location::DEFAULT_GRID_SPACING_MILES}
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'hours' => 'array',
            'address_components' => 'array',
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'is_storefront' => 'boolean',
            'geocode_failed' => 'boolean',
            'county_geoids' => 'array',
            'served_towns' => 'array',
            'grounding_cache' => 'array',
            'coverage_suggestions' => 'array',
            'grid_spacing_miles' => 'decimal:2',
        ];
    }

    /** Default grid point spacing (miles) when a location carries no per-location override — the Local Falcon parity value. */
    public const DEFAULT_GRID_SPACING_MILES = 1.5;

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

    /** The grid point spacing (miles) to scan this location at — its override, else the default. */
    public function gridSpacingMiles(): float
    {
        return $this->grid_spacing_miles !== null
            ? (float) $this->grid_spacing_miles
            : self::DEFAULT_GRID_SPACING_MILES;
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
