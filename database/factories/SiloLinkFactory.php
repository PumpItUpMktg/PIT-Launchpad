<?php

namespace Database\Factories;

use App\Models\Silo;
use App\Models\SiloLink;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiloLink>
 */
class SiloLinkFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'from_silo_id' => Silo::factory(),
            'to_silo_id' => Silo::factory(),
            'relation' => 'cross_silo',
        ];
    }
}
