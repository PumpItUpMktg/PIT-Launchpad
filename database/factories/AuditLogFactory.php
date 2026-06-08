<?php

namespace Database\Factories;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => null,
            'action' => fake()->randomElement(AuditAction::cases()),
            'target_type' => null,
            'target_id' => null,
            'metadata' => [],
            'created_at' => now(),
        ];
    }
}
