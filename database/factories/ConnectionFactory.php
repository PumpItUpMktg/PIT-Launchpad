<?php

namespace Database\Factories;

use App\Enums\ConnectionProvider;
use App\Models\Connection;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Connection>
 */
class ConnectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'provider' => fake()->randomElement(ConnectionProvider::cases()),
            'credentials' => ['token' => fake()->sha256()],
            'scopes' => ['read', 'write'],
            'status' => 'active',
            'last_rotated_at' => now(),
            // Pilot reality: credentials are treated as exposed until rotated.
            'compromised' => true,
            'compromised_reason' => 'pilot exposure',
            'exposed_at' => now(),
        ];
    }

    /**
     * A credential that has been rotated since exposure and passes the gate.
     */
    public function rotated(): static
    {
        return $this->state(fn () => [
            'compromised' => false,
            'compromised_reason' => null,
            'exposed_at' => now()->subDay(),
            'last_rotated_at' => now(),
        ]);
    }

    public function compromised(?string $reason = 'pilot exposure'): static
    {
        return $this->state(fn () => [
            'compromised' => true,
            'compromised_reason' => $reason,
            'exposed_at' => now(),
        ]);
    }
}
