<?php

namespace Database\Factories;

use App\Enums\ConversionSource;
use App\Enums\ConversionType;
use App\Models\Conversion;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversion>
 */
class ConversionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'type' => fake()->randomElement(ConversionType::cases()),
            'source' => ConversionSource::Manual,
            'count' => 1,
            'occurred_at' => now(),
        ];
    }
}
