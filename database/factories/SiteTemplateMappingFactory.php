<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteTemplateMapping;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteTemplateMapping>
 */
class SiteTemplateMappingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'kit' => 'service-page',
            'template_id' => $this->faker->numberBetween(10, 999),
            'template_title' => 'Service Page',
            'version' => 1,
        ];
    }
}
