<?php

namespace Database\Factories;

use App\Models\Content;
use App\Models\ContentVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentVersion>
 */
class ContentVersionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'content_id' => Content::factory(),
            'version' => 1,
            'payload_snapshot' => ['title' => fake()->sentence(), 'slots' => []],
            'created_by' => null,
        ];
    }
}
