<?php

namespace Database\Factories;

use App\Models\ReviewRequest;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReviewRequest>
 */
class ReviewRequestFactory extends Factory
{
    protected $model = ReviewRequest::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'token' => hash('sha256', Str::random(48)),
            'payload' => ['customer_email' => $this->faker->safeEmail()],
            'sent_at' => now(),
            'expires_at' => now()->addDays(30),
            'reminder_count' => 0,
        ];
    }
}
