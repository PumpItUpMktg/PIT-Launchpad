<?php

namespace Database\Factories;

use App\Enums\ReviewSource;
use App\Enums\ReviewStatus;
use App\Models\Review;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'source' => ReviewSource::FirstParty,
            'status' => ReviewStatus::Pending,
            'rating' => $this->faker->numberBetween(1, 5),
            'body' => $this->faker->sentence(12),
            'customer_name' => $this->faker->firstName().' '.strtoupper($this->faker->randomLetter()).'.',
            'customer_email' => $this->faker->safeEmail(),
            'reviewed_at' => now(),
            'submitted_at' => now(),
            'needs_location' => false,
        ];
    }

    public function imported(): static
    {
        return $this->state(fn (): array => ['source' => ReviewSource::Imported, 'import_source' => 'google']);
    }

    public function published(): static
    {
        return $this->state(fn (): array => ['status' => ReviewStatus::Published, 'approved_at' => now(), 'published_at' => now()]);
    }
}
