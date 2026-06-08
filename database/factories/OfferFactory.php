<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'name' => fake()->words(3, true),
            'terms' => fake()->sentence(),
            'active_window' => ['start' => '2026-01-01', 'end' => '2026-12-31'],
        ];
    }
}
