<?php

namespace Database\Factories;

use App\Enums\MarketTier;
use App\Models\Market;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Market>
 */
class MarketFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'name' => fake()->city(),
            'geo_id' => fake()->numerify('#####'),
            'region' => fake()->randomElement(['TX', 'CA', 'FL', 'NY', 'WA', 'AZ', 'CO']),
            'tier' => fake()->randomElement(MarketTier::cases()),
            'lat' => fake()->latitude(),
            'lng' => fake()->longitude(),
            'demographics' => ['population' => fake()->numberBetween(10000, 500000)],
            'neighborhoods' => [fake()->streetName(), fake()->streetName()],
            'local_nuances' => fake()->sentence(),
            'is_covered' => fake()->boolean(),
        ];
    }

    public function priority(): static
    {
        return $this->state(fn () => ['tier' => MarketTier::Priority]);
    }

    public function coverage(): static
    {
        return $this->state(fn () => ['tier' => MarketTier::Coverage]);
    }
}
