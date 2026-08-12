<?php

namespace Database\Factories;

use App\Enums\JobSource;
use App\Enums\JobStatus;
use App\Models\Job;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Job>
 */
class JobFactory extends Factory
{
    protected $model = Job::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'source' => JobSource::Manual,
            'status' => JobStatus::Captured,
            'client_name_full' => fake()->name(),
            'client_name_display' => fake()->firstName().' '.strtoupper(fake()->randomLetter()).'.',
            'address_true' => fake()->streetAddress(),
            'lat_true' => 40.7128,
            'lng_true' => -74.0060,
            'lat_jittered' => 40.7130,
            'lng_jittered' => -74.0055,
            'photos' => [],
            'primary_photo_index' => 0,
            'raw_description' => fake()->sentence(14),
        ];
    }

    /** A job that has been approved and pushed live to WordPress. */
    public function published(): static
    {
        return $this->state(fn (): array => ['status' => JobStatus::Published]);
    }
}
