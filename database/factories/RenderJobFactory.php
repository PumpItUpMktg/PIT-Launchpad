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
            'slot' => 'hero_image',
            'prompt' => fake()->sentence(),
            'provider' => 'fal',
            'status' => RenderStatus::Queued,
            'r2_key' => null,
            'error' => null,
            'timeout' => 120,
            'seo_filename' => 'hero.webp',
            'alt' => fake()->sentence(3),
            'title' => fake()->words(2, true),
            'caption' => null,
            'required' => true,
            'attempts' => 0,
            'width' => 1200,
            'height' => 675,
        ];
    }

    public function rendered(): static
    {
        return $this->state(fn () => [
            'status' => RenderStatus::Succeeded,
            'r2_key' => 'sites/test/hero.webp',
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => RenderStatus::RenderFailed,
            'attempts' => 3,
            'error' => 'fal returned HTTP 500',
        ]);
    }
}
