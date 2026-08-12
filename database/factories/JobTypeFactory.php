<?php

namespace Database\Factories;

use App\Enums\JobTypeSource;
use App\Models\JobType;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobType>
 */
class JobTypeFactory extends Factory
{
    protected $model = JobType::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $label = str(fake()->unique()->word())->title().' Repair';

        return [
            'site_id' => Site::factory(),
            'label' => (string) $label,
            'slug' => str($label)->slug(),
            'silo_id' => null,
            'source' => JobTypeSource::Native,
        ];
    }
}
