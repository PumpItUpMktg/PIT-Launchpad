<?php

namespace Database\Factories;

use App\Enums\RenderStatus;
use App\Models\RenderJob;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RenderJob>
 */
class RenderJobFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'content_id' => null,
            'prompt' => fake()->sentence(),
            'provider' => 'fal',
            'status' => RenderStatus::Queued,
            'r2_key' => null,
            'error' => null,
            'timeout' => 120,
        ];
    }
}
