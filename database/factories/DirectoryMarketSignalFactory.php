<?php

namespace Database\Factories;

use App\Models\Directory;
use App\Models\DirectoryMarketSignal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DirectoryMarketSignal>
 */
class DirectoryMarketSignalFactory extends Factory
{
    protected $model = DirectoryMarketSignal::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'directory_id' => Directory::factory(),
            'geo_value' => $this->faker->city(),
            'ranks_for_local_terms' => $this->faker->boolean(),
            'competitor_count' => $this->faker->numberBetween(0, 10),
            'seo_value_local' => $this->faker->numberBetween(0, 100),
        ];
    }
}
