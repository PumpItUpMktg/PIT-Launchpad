<?php

namespace Database\Factories;

use App\Enums\PlatformSecret;
use App\Models\PlatformSecretRotation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformSecretRotation>
 */
class PlatformSecretRotationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'platform_secret' => fake()->randomElement(PlatformSecret::cases()),
            'rotated_at' => now(),
            'rotated_by' => null,
        ];
    }

    public function secret(PlatformSecret $secret): static
    {
        return $this->state(fn () => ['platform_secret' => $secret]);
    }
}
