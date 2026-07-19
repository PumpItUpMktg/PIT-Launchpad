<?php

namespace Database\Factories;

use App\Models\KeywordCluster;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KeywordCluster>
 */
class KeywordClusterFactory extends Factory
{
    protected $model = KeywordCluster::class;

    public function definition(): array
    {
        $head = fake()->unique()->words(2, true);

        return [
            'site_id' => Site::factory(),
            'label' => ucwords($head),
            'head_term' => $head,
            'head_canonical' => $head,
            'intent' => fake()->randomElement(['transactional', 'commercial', 'informational']),
            'volume' => fake()->numberBetween(100, 8000),
            'member_count' => fake()->numberBetween(3, 12),
            'dropped' => false,
            'serp_status' => 'unvalidated',
        ];
    }
}
