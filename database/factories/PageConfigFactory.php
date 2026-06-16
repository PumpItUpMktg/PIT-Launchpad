<?php

namespace Database\Factories;

use App\Models\Content;
use App\Models\PageConfig;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PageConfig>
 */
class PageConfigFactory extends Factory
{
    protected $model = PageConfig::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'content_id' => Content::factory(),
            'hero_variant' => 'cta',
            'form_embed' => null,
            'phone_override' => null,
            'hero_image_override' => null,
            'market_ref' => null,
        ];
    }
}
