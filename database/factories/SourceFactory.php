<?php

namespace Database\Factories;

use App\Enums\SourceType;
use App\Models\Site;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Source>
 */
class SourceFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'silo_id' => null,
            'type' => fake()->randomElement(SourceType::cases()),
            'config' => ['feed_url' => fake()->url()],
            'schedule' => '0 6 * * *',
        ];
    }
}
