<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\TechDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TechDevice>
 */
class TechDeviceFactory extends Factory
{
    protected $model = TechDevice::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'name' => fake()->name(),
            'phone' => '+1'.fake()->numerify('##########'),
            'email' => fake()->safeEmail(),
        ];
    }

    /** A device that has been revoked (tech churn). */
    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()]);
    }
}
