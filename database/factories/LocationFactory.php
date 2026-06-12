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
        ];
    }
}
