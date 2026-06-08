<?php

namespace Database\Factories;

use App\Enums\CompetitorType;
use App\Models\Competitor;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Competitor>
 */
class CompetitorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'site_id' => Site::factory(),
            'name' => $name,
            'domain' => Str::slug($name).'.com',
            'type' => fake()->randomElement(CompetitorType::cases()),
            'market_refs' => [],
        ];
    }
}
