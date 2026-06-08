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
        ];
    }
}
