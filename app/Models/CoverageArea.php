<?php

namespace App\Models;

use App\Enums\MunicipalityType;
use App\Models\Concerns\BelongsToSite;
use Database\Factories\CoverageAreaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One municipality in a tenant's authoritative service-area coverage set (the union of
 * the Census enumeration across all base Locations' radii). The Phase-3 dependency:
 * keyword volume is localized against this set. A selected subset becomes the
 * location-page Markets (a later layer).
 *
 * @property string $id
 * @property string $site_id
 * @property string $geo_id
 * @property string $name
 * @property MunicipalityType $type
 * @property string|null $state
 * @property float|null $lat
 * @property float|null $lng
 * @property float|null $distance_miles
 * @property list<string>|null $source_location_ids
 * @property int|null $population ACS5 total population (for Large/Medium/Small grouping)
 * @property string $source county (auto) | manual (owner-added, directed → priority page candidate)
 */
class CoverageArea extends Model
{
    /** @use HasFactory<CoverageAreaFactory> */
    use BelongsToSite, HasFactory, HasUlids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => MunicipalityType::class,
            'lat' => 'decimal:7',
            'lng' => 'decimal:7',
            'distance_miles' => 'decimal:2',
            'source_location_ids' => 'array',
        ];
    }
}
