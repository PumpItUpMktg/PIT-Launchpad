<?php

namespace Database\Factories;

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\IntakeType;
use App\Enums\PageType;
use App\Models\Content;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Content>
 */
class ContentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(4);

        return [
            'site_id' => Site::factory(),
            'silo_id' => null,
            'kind' => ContentKind::Page,
            'page_type' => PageType::Service,
            'intake_type' => null,
            'title' => rtrim($title, '.'),
            'slug' => fake()->unique()->slug(),
            'status' => ContentStatus::Candidate,
            'seo_profile' => 'default',
            'meta' => ['title' => $title, 'description' => fake()->sentence()],
            'schema_type' => 'WebPage',
            'schema_payload' => [],
            'slot_payload' => ['hero' => ['heading' => fake()->sentence()]],
            'body' => null,
            'voice_profile_version' => 1,
            'version' => 1,
        ];
    }

    public function page(): static
    {
        return $this->state(fn () => [
            'kind' => ContentKind::Page,
            'page_type' => PageType::Service,
            'intake_type' => null,
            'body' => null,
        ]);
    }

    public function post(): static
    {
        return $this->state(fn () => [
            'kind' => ContentKind::Post,
            'page_type' => null,
            'intake_type' => IntakeType::Reactive,
            'slot_payload' => null,
            'body' => fake()->paragraphs(3, true),
        ]);
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => ContentStatus::Published,
            'published_at' => now(),
            'wp_post_id' => fake()->numberBetween(1, 9999),
        ]);
    }
}
