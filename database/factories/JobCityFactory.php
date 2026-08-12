<?php

namespace Database\Factories;

use App\Enums\MunicipalityType;
use App\Enums\SizeTier;
use App\Models\JobCity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobCity>
 */
class JobCityFactory extends Factory
{
    protected $model = JobCity::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->city();

        return [
            'place_geoid' => '34023'.fake()->unique()->numerify('#####'),   // 10-digit county-subdivision GEOID
            'name' => $name,
            'state' => 'NJ',
            'type' => MunicipalityType::CountySubdivision,
            'lat' => 40.7,
            'lng' => -74.5,
            'population' => fake()->numberBetween(1_000, 60_000),
            'size_tier' => SizeTier::Medium,
            'slug' => str($name)->slug().'-nj',
        ];
    }
}
