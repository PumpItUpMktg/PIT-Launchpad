<?php

namespace Database\Factories;

use App\Models\KeywordCorpus;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KeywordCorpus>
 */
class KeywordCorpusFactory extends Factory
{
    protected $model = KeywordCorpus::class;

    public function definition(): array
    {
        $term = fake()->unique()->words(3, true);

        return [
            'site_id' => Site::factory(),
            'term' => $term,
            'canonical' => $term,
            'volume' => fake()->numberBetween(0, 8000),
            'difficulty' => fake()->numberBetween(0, 100),
            'competition' => fake()->randomFloat(4, 0, 1),
            'intent' => fake()->randomElement(['transactional', 'commercial', 'informational']),
            'source' => 'expansion',
            'seed_term' => null,
            'disposition' => null,
            'cluster_id' => null,
            'last_refreshed_at' => now(),
        ];
    }
}
