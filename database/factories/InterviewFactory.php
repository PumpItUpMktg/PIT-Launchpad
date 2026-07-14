<?php

namespace Database\Factories;

use App\Enums\InterviewStatus;
use App\Models\Interview;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Interview>
 */
class InterviewFactory extends Factory
{
    protected $model = Interview::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'status' => InterviewStatus::InProgress,
            'started_at' => now(),
        ];
    }
}
