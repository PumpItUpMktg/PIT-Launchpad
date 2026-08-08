<?php

namespace Database\Factories;

use App\Enums\MunicipalityType;
use App\Models\CoverageArea;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CoverageArea>
 */
class CoverageAreaFactory extends Factory
{
    protected $model = CoverageArea::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            // A valid 10-digit county-subdivision GEOID in Middlesex County, NJ (FIPS 34023) — same
            // county by default so multi-town fixtures stay geographically coherent; unique cousub suffix.
            'geo_id' => '34023'.$this->faker->unique()->numerify('#####'),
            'name' => $this->faker->city(),
            'type' => MunicipalityType::CountySubdivision,
            'state' => 'NJ',
            'lat' => 40.7,
            'lng' => -74.5,
            'distance_miles' => 8.0,
            'source_location_ids' => [],
        ];
    }
}
