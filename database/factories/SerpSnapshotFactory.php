<?php

namespace Database\Factories;

use App\Models\Content;
use App\Models\SerpSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SerpSnapshot>
 */
class SerpSnapshotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'content_id' => Content::factory(),
            'query' => fake()->words(3, true),
            'captured_at' => now(),
            'competitor_analysis' => [],
            'diff' => [],
        ];
    }
}
