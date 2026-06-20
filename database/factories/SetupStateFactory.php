<?php

namespace Database\Factories;

use App\Models\SetupState;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SetupState>
 */
class SetupStateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'current_step' => 1,
            'services_done' => false,
            'territory_done' => false,
            'structure_finalized' => false,
            'approved' => false,
            'launched' => false,
            'localize' => true,
            'town_page_pace' => 5,
            'fresh_content' => true,
        ];
    }
}
