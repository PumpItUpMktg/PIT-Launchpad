<?php

namespace Database\Factories;

use App\Enums\SpokeGranularity;
use App\Enums\SpokePageType;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Spoke>
 */
class SpokeFactory extends Factory
{
    protected $model = Spoke::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'silo_blueprint_id' => SiloBlueprint::factory(),
            'site_id' => Site::factory(),
            'silo' => 'Water Heater',
            'is_pillar' => false,
            'name' => 'Water Heater Repair',
            'page_type' => SpokePageType::Service,
            'tag' => SpokeTag::Core,
            'head_keyword' => 'water heater repair',
            'volume' => null,
            'status' => SpokeStatus::Offered,
            'connection_note' => null,
            'granularity' => SpokeGranularity::OwnPage,
        ];
    }
}
