<?php

namespace Database\Factories;

use App\Enums\CitationSource;
use App\Enums\CitationState;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CitationStatus>
 */
class CitationStatusFactory extends Factory
{
    protected $model = CitationStatus::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $site = Site::factory();

        return [
            'site_id' => $site,
            'location_id' => Location::factory()->for($site),
            'directory_id' => Directory::factory(),
            'state' => CitationState::NotListed,
            'attribution_confidence' => null,
            'source' => CitationSource::Unknown,
            'last_scanned_at' => now(),
        ];
    }

    public function inState(CitationState $state): static
    {
        return $this->state(fn (): array => ['state' => $state]);
    }
}
