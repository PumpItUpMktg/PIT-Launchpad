<?php

namespace Database\Factories;

use App\Models\ConversionConfig;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConversionConfig>
 */
class ConversionConfigFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'primary_actions' => ['call', 'book'],
            'tracked_numbers' => ['main' => fake()->phoneNumber()],
            'lead_destination' => ['type' => 'ghl', 'pipeline' => 'inbound'],
            'forms' => ['contact' => ['fields' => ['name', 'email', 'phone']]],
            'analytics_ids' => ['ga4' => 'G-'.fake()->bothify('########')],
            'booking_system' => fake()->randomElement(['housecallpro', 'servicetitan', null]),
        ];
    }
}
