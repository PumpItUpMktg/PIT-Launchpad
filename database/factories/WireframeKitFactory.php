<?php

namespace Database\Factories;

use App\Models\WireframeKit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WireframeKit>
 */
class WireframeKitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => null,
            'name' => fake()->words(2, true).' Kit',
            'slot_schema' => [
                'hero' => ['heading' => ['max' => 60], 'subheading' => ['max' => 140]],
                'body' => ['min_words' => 300],
            ],
        ];
    }
}
