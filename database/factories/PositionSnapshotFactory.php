<?php

namespace Database\Factories;

use App\Enums\BeatabilityLane;
use App\Models\Keyword;
use App\Models\PositionSnapshot;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PositionSnapshot>
 */
class PositionSnapshotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'keyword_id' => Keyword::factory(),
            'market_id' => null,
            'lane' => BeatabilityLane::Organic,
            'rank' => fake()->numberBetween(1, 100),
            'ranking_url' => fake()->url(),
            'serp_features' => [],
            'captured_at' => now(),
        ];
    }

    public function organic(int $rank): static
    {
        return $this->state(fn () => [
            'lane' => BeatabilityLane::Organic,
            'rank' => $rank,
            'market_id' => null,
        ]);
    }

    public function localPack(): static
    {
        return $this->state(fn () => [
            'lane' => BeatabilityLane::LocalPack,
            'rank' => null,
            'ranking_url' => null,
            'avg_rank' => fake()->randomFloat(2, 1, 20),
            'pct_top3' => fake()->randomFloat(4, 0, 1),
            'coverage' => fake()->randomFloat(4, 0, 1),
        ]);
    }
}
