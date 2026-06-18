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
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
