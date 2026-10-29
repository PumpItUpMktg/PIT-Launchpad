<?php

namespace Database\Factories;

use App\Models\LeadForm;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadForm>
 */
class LeadFormFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $slug = fake()->unique()->slug(2);

        return [
            'site_id' => Site::factory(),
            'name' => ucfirst(str_replace('-', ' ', $slug)).' form',
            'embed' => '<iframe src="https://forms.example/'.$slug.'?embed=1"></iframe>',
        ];
    }
}
