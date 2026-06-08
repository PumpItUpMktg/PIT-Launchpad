<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceProblem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceProblem>
 */
class ServiceProblemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'phrase' => fake()->sentence(),
            'intent' => fake()->randomElement(['informational', 'transactional', 'commercial']),
        ];
    }
}
