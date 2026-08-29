<?php

use App\Citations\Ui\CitationReport;
use App\Enums\CitationLifecycleState;
use App\Enums\CitationPresence;
use App\Enums\DirectoryScope;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Support\CurrentSite;

beforeEach(function (): void {
    $this->site = Site::factory()->create();
    CurrentSite::set($this->site->id);
    $this->location = Location::factory()->for($this->site)->create(['name' => 'Bedminster']);
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $this->location->id, 'categories' => null]);
    $this->report = new CitationReport;
});

function reportStatus(mixed $ctx, string $dirName, CitationPresence $presence, CitationLifecycleState $lifecycle = CitationLifecycleState::None, ?array $mismatch = null): void
{
    $dir = Directory::factory()->create(['name' => $dirName, 'scope' => DirectoryScope::National]);
    CitationStatus::factory()->for($ctx->site)->create([
        'location_id' => $ctx->location->id, 'directory_id' => $dir->id,
        'presence' => $presence, 'lifecycle' => $lifecycle, 'mismatch_fields' => $mismatch,
    ]);
}

test('the report translates the records into client-readable counts', function (): void {
    reportStatus($this, 'Yelp', CitationPresence::PresentMatch);
    reportStatus($this, 'BBB', CitationPresence::PresentMismatch, mismatch: ['phone' => ['found' => '(908) 555-0100', 'expected' => '(908) 555-0142']]);
    reportStatus($this, 'Angi', CitationPresence::Absent, CitationLifecycleState::Submitted);
    reportStatus($this, 'Foursquare', CitationPresence::Absent);
    Directory::factory()->create(['name' => 'Nextdoor', 'scope' => DirectoryScope::National]); // eligible, no status → available

    $data = $this->report->forLocation($this->location);

    expect($data->locationName)->toBe('Bedminster')
        ->and($data->listedCorrectly)->toBe(1)
        ->and($data->wrongInformation)->toBe(1)
        ->and($data->beingAdded)->toBe(1)
        ->and($data->stillAvailable)->toBe(2)          // Foursquare + Nextdoor
        ->and($data->available)->toContain('Nextdoor')
        ->and($data->corrections[0]['directory'])->toBe('BBB')
        ->and($data->corrections[0]['fields'][0])->toBe(['field' => 'Phone', 'found' => '(908) 555-0100', 'expected' => '(908) 555-0142']);
});
