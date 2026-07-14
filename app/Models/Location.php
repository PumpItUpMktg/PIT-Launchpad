<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
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
 * @property string|null $place_id
 * @property array<int, array<string, mixed>>|null $served_towns GBP service-area towns {name, state, lat, lng, geocoded} — one location owns a town per site
 * @property string|null $market_notes operator free-text market context, fed VERBATIM to the location-page drafter
 * @property array<string, mixed>|null $grounding_cache cached local facts {facts, sources, fetched_at} (90-day staleness)
 * @property array<string, mixed>|null $coverage_suggestions extraction prompts (gathering relay): {towns: list<string> conflicting candidates, phrases: list<string> unresolved coverage phrases}
 * @property string|null $primary_category the GBP primary category label
 */
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
        ];
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
