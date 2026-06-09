<?php

namespace Database\Factories;

use App\Enums\SerpTaskState;
use App\Models\SerpTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SerpTask>
 */
class SerpTaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'function' => 'organic',
            'task_id' => (string) fake()->numerify('###########-####-####-####-############'),
            'cache_key' => 'dfs:organic:2840:en:'.fake()->slug(),
            'query' => fake()->words(2, true),
            'location_code' => 2840,
            'language_code' => 'en',
            'state' => SerpTaskState::Pending,
        ];
    }
}
