<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'name' => fake()->city().' Office',
            'address' => fake()->streetAddress(),
            'phone' => '+1'.fake()->numerify('##########'),
            'email' => fake()->companyEmail(),
            // Per-day shape: {"mon": {"open","close"}, "sun": "closed", …}.
            'hours' => [
                'mon' => ['open' => '08:00', 'close' => '17:00'],
                'tue' => ['open' => '08:00', 'close' => '17:00'],
                'wed' => ['open' => '08:00', 'close' => '17:00'],
                'thu' => ['open' => '08:00', 'close' => '17:00'],
                'fri' => ['open' => '08:00', 'close' => '17:00'],
                'sat' => 'closed',
                'sun' => 'closed',
            ],
            'is_storefront' => fake()->boolean(),
            'booking_url' => fake()->optional()->url(),
            // Follow PRODUCTION: a newly created location is held until reviewed (the model's creating
            // hook). A test that publishes a location page opts in explicitly — `->released()` (below) or
            // `['publish_held' => false]` — so the held path is the default a test must consciously leave,
            // not a condition a fixture quietly arranges.
            'publish_held' => true,
        ];
    }

    /** A location reviewed and released for publishing — the explicit opt-in for tests that publish its pages. */
    public function released(): static
    {
        return $this->state(fn (array $attributes): array => ['publish_held' => false]);
    }
}
