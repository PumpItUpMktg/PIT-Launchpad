<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteNarrative;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteNarrative>
 */
class SiteNarrativeFactory extends Factory
{
    protected $model = SiteNarrative::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'story' => $this->faker->paragraphs(2, true),
            'mission' => $this->faker->sentence(14),
            'values' => [
                ['title' => 'Show up on time', 'description' => 'Every appointment, every time.'],
                ['title' => 'Quote before we start', 'description' => 'No surprises on the bill.'],
                ['title' => 'Leave it clean', 'description' => 'Cleaner than we found it.'],
            ],
            'differentiators' => [
                ['title' => 'Licensed & insured', 'description' => 'Background-checked crews.'],
                ['title' => 'Written warranty', 'description' => 'Every job backed in writing.'],
                ['title' => 'Same-day service', 'description' => 'Most calls handled today.'],
            ],
            'team' => null,
        ];
    }
}
