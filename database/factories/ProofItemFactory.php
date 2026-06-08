<?php

namespace Database\Factories;

use App\Enums\ProofType;
use App\Models\ProofItem;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProofItem>
 */
class ProofItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'type' => fake()->randomElement(ProofType::cases()),
            'payload' => ['label' => fake()->words(3, true)],
            'is_substantiated' => fake()->boolean(70),
            'evidence' => fake()->optional()->url(),
        ];
    }
}
