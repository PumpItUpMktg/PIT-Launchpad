<?php

namespace Database\Factories;

use App\Enums\CitationEventType;
use App\Models\CitationEvent;
use App\Models\Directory;
use App\Models\Location;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CitationEvent>
 */
class CitationEventFactory extends Factory
{
    protected $model = CitationEvent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $site = Site::factory();

        return [
            'site_id' => $site,
            'location_id' => Location::factory()->for($site),
            'directory_id' => Directory::factory(),
            'event_type' => CitationEventType::Discovered,
            'occurred_at' => now(),
        ];
    }
}
