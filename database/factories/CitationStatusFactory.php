<?php

namespace Database\Factories;

use App\Enums\CitationLifecycleState;
use App\Enums\CitationPresence;
use App\Enums\CitationSource;
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
            'presence' => CitationPresence::Absent,
            'lifecycle' => CitationLifecycleState::None,
            'attribution_confidence' => null,
            'source' => CitationSource::Unknown,
            'last_scanned_at' => now(),
        ];
    }

    public function presence(CitationPresence $presence): static
    {
        return $this->state(fn (): array => ['presence' => $presence]);
    }

    public function lifecycle(CitationLifecycleState $lifecycle): static
    {
        return $this->state(fn (): array => ['lifecycle' => $lifecycle]);
    }
}
