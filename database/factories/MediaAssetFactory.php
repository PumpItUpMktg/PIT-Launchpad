<?php

namespace Database\Factories;

use App\Enums\MediaKind;
use App\Enums\MediaSource;
use App\Models\MediaAsset;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaAsset>
 */
class MediaAssetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'kind' => fake()->randomElement(MediaKind::cases()),
            'source' => fake()->randomElement(MediaSource::cases()),
            'service_tags' => [],
            'market_tags' => [],
            'rights_ok' => fake()->boolean(80),
            'r2_key' => 'media/'.fake()->uuid().'.jpg',
            'alt_text' => fake()->sentence(),
            'dimensions' => ['width' => 1200, 'height' => 800],
        ];
    }
}
