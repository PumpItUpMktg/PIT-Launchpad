<?php

namespace Database\Factories;

use App\Enums\SourceDocType;
use App\Models\Site;
use App\Models\SourceDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SourceDocument>
 */
class SourceDocumentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'type' => fake()->randomElement(SourceDocType::cases()),
            'r2_key' => 'docs/'.fake()->uuid().'.pdf',
            'grounding_enabled' => true,
            'extracted_text' => fake()->paragraph(),
        ];
    }
}
