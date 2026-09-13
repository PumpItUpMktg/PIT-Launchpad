<?php

namespace Database\Factories;

use App\Models\InterviewInvite;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InterviewInvite>
 */
class InterviewInviteFactory extends Factory
{
    protected $model = InterviewInvite::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'token' => hash('sha256', Str::random(48)),
            'issued_at' => now(),
            'expires_at' => now()->addDays(30),
            'open_count' => 0,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subDay()]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }
}
