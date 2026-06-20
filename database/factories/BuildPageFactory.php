<?php

namespace Database\Factories;

use App\Enums\BuildSource;
use App\Enums\BuildStatus;
use App\Models\BuildPage;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BuildPage>
 */
class BuildPageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'source' => BuildSource::Standard,
            'page_key' => fake()->unique()->slug(),
            'title' => fake()->words(2, true),
            'recipe' => 'standard.home',
            'status' => BuildStatus::Queued,
            'priority' => 100,
            'review_required' => false,
            'spoke_id' => null,
        ];
    }
}
