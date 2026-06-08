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
            'phone' => fake()->phoneNumber(),
            'email' => fake()->companyEmail(),
            'hours' => ['mon' => '8-5', 'tue' => '8-5', 'wed' => '8-5'],
            'is_storefront' => fake()->boolean(),
            'booking_url' => fake()->optional()->url(),
        ];
    }
}
