<?php

namespace Database\Factories;

use App\Enums\KeywordSource;
use App\Models\Keyword;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Keyword>
 */
class KeywordFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'silo_id' => null,
            'query' => fake()->words(3, true),
            'intent' => fake()->randomElement(['informational', 'transactional', 'commercial']),
            'source' => fake()->randomElement(KeywordSource::cases()),
            'volume' => fake()->numberBetween(0, 50000),
            'difficulty' => fake()->numberBetween(0, 100),
            'opportunity_score' => fake()->randomFloat(4, 0, 100),
            'beatability' => fake()->randomFloat(4, 0, 1),
            'status' => 'candidate',
        ];
    }
}
