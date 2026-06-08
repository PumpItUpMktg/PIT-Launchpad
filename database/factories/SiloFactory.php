<?php

namespace Database\Factories;

use App\Enums\SiloType;
use App\Models\Silo;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Silo>
 */
class SiloFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'name' => fake()->words(2, true),
            'type' => fake()->randomElement(SiloType::cases()),
            'parent_silo_id' => null,
            'rule_set' => ['include' => [], 'exclude' => []],
            'wp_category_id' => null,
            'status' => 'active',
        ];
    }

    public function servicePillar(): static
    {
        return $this->state(fn () => ['type' => SiloType::ServicePillar]);
    }

    public function topical(): static
    {
        return $this->state(fn () => ['type' => SiloType::Topical]);
    }
}
