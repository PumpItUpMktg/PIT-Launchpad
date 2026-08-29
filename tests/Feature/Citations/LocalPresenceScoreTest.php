<?php

use App\Citations\LocalPresenceScore;
use App\Enums\CitationState;
use App\Enums\DirectoryScope;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\MetricSnapshot;
use App\Models\Site;
use App\Support\CurrentSite;

beforeEach(function (): void {
    $this->site = Site::factory()->create();
    CurrentSite::set($this->site->id);
    $this->score = new LocalPresenceScore;
});

test('a fully-listed location scores 100', function (): void {
    $location = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $location->id, 'categories' => null]);
    $dir = Directory::factory()->create(['scope' => DirectoryScope::National, 'seo_value' => 50]);
    CitationStatus::factory()->for($this->site)->create([
        'location_id' => $location->id, 'directory_id' => $dir->id, 'state' => CitationState::ListedCorrect,
    ]);

    expect($this->score->forLocation($location)['score'])->toBe(100);
});

test('unverified and gap listings score partial and zero credit, weighted by value', function (): void {
    $location = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $location->id, 'categories' => null]);

    // Two equal-weight directories: one present-unconfirmed (0.5), one an unrecorded gap (0.0). Score = 25.
    $a = Directory::factory()->create(['scope' => DirectoryScope::National, 'seo_value' => 40]);
    $b = Directory::factory()->create(['scope' => DirectoryScope::National, 'seo_value' => 40]);
    CitationStatus::factory()->for($this->site)->create([
        'location_id' => $location->id, 'directory_id' => $a->id, 'state' => CitationState::Unverified,
    ]);

    $result = $this->score->forLocation($location);
    expect($result['applicable'])->toBe(2)
        ->and($result['score'])->toBe(25);
});

test('a location with nothing applicable scores 100 vacuously', function (): void {
    $location = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $location->id, 'categories' => null]);

    expect($this->score->forLocation($location)['score'])->toBe(100);
});

test('snapshot writes an idempotent monthly metric row', function (): void {
    $location = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $location->id, 'categories' => null]);
    $dir = Directory::factory()->create(['scope' => DirectoryScope::National, 'seo_value' => 50]);
    CitationStatus::factory()->for($this->site)->create([
        'location_id' => $location->id, 'directory_id' => $dir->id, 'state' => CitationState::ListedCorrect,
    ]);

    $this->score->snapshot($location);
    $this->score->snapshot($location); // re-run same month → upsert, not a second row

    $rows = MetricSnapshot::query()
        ->where('provider', 'citations')->where('metric_key', 'local_presence_score')
        ->where('dimension_type', 'location')->where('dimension_value', $location->id)
        ->get();

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()->value_numeric)->toBe(100);
});
