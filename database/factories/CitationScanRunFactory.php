<?php

namespace Database\Factories;

use App\Models\CitationScanRun;
use App\Models\Location;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CitationScanRun>
 */
class CitationScanRunFactory extends Factory
{
    protected $model = CitationScanRun::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $site = Site::factory();

        return [
            'site_id' => $site,
            'location_id' => Location::factory()->for($site),
            'trigger' => 'scheduled',
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }
}
