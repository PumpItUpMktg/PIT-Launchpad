<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteBranding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteBranding>
 */
class SiteBrandingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'logo_set' => ['primary' => 'logo.svg', 'mark' => 'mark.svg'],
            'palette' => ['primary' => '#0F62FE', 'accent' => '#FF6F00'],
            'typography' => ['heading' => 'Inter', 'body' => 'Inter'],
            'imagery_style' => fake()->word(),
            'social_handles' => ['twitter:site' => '@'.fake()->userName()],
            'default_share_image' => 'share.png',
            'default_card_type' => 'summary_large_image',
            'entity_type' => 'LocalBusiness',
            'same_as' => [fake()->url()],
            'canonical_nap' => [
                'name' => fake()->company(),
                'address' => fake()->streetAddress(),
                'phone' => fake()->phoneNumber(),
            ],
        ];
    }
}
