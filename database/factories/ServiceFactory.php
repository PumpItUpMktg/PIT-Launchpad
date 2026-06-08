<?php

namespace Database\Factories;

use App\Enums\GeoApplicability;
use App\Enums\ServiceSiloRole;
use App\Models\Service;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
            'scope' => fake()->sentence(),
            'silo_role' => fake()->randomElement(ServiceSiloRole::cases()),
            'gbp_service_type_id' => fake()->optional()->bothify('job_type_id:####'),
            'pricing_posture' => fake()->randomElement(['premium', 'value', 'competitive']),
            'is_most_profitable' => fake()->boolean(20),
            'is_growth_priority' => fake()->boolean(30),
            'primary_cta_intent' => fake()->randomElement(['call', 'book', 'quote']),
            'geo_applicability' => GeoApplicability::All,
            'peak_months' => fake()->randomElements([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12], 3),
        ];
    }
}
