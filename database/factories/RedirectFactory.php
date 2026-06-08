<?php

namespace Database\Factories;

use App\Enums\RedirectSource;
use App\Models\Redirect;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Redirect>
 */
class RedirectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'from_url' => '/'.fake()->slug(),
            'to_url' => '/'.fake()->slug(),
            'code' => 301,
            'source' => fake()->randomElement(RedirectSource::cases()),
            'status' => 'active',
        ];
    }
}
