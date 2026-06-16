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
            'geo_id' => (string) $this->faker->unique()->numerify('34######'),
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
