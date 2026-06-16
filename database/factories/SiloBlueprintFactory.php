<?php

namespace Database\Factories;

use App\Models\SiloBlueprint;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiloBlueprint>
 */
class SiloBlueprintFactory extends Factory
{
    protected $model = SiloBlueprint::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'trade' => 'plumbing',
            'seed' => null,
        ];
    }
}
