<?php

namespace Database\Factories;

use App\Enums\SizeTier;
use App\Models\JobCounty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobCounty>
 */
class JobCountyFactory extends Factory
{
    protected $model = JobCounty::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->city();

        return [
            'county_geoid' => '34'.fake()->unique()->numerify('###'),   // NJ (34) + 3-digit county
            'state_fips' => '34',
            'name' => $name.' County',
            'state' => 'NJ',
            'population' => fake()->numberBetween(10_000, 800_000),
            'size_tier' => SizeTier::Large,
            'slug' => str($name)->slug().'-county-nj',
        ];
    }
}
