<?php

namespace Database\Factories;

use App\Models\Goal;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Goal>
 */
class GoalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'metric' => fake()->randomElement(['leads', 'calls', 'bookings', 'revenue']),
            'target' => fake()->numberBetween(10, 1000),
            'period' => fake()->randomElement(['monthly', 'quarterly', 'annually']),
        ];
    }
}
