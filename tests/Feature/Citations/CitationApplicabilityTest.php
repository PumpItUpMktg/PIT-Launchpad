<?php

use App\Citations\CitationApplicability;
use App\Enums\DirectoryScope;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Support\CurrentSite;

beforeEach(function (): void {
    $this->site = Site::factory()->create();
    CurrentSite::set($this->site->id);
    $this->applicability = new CitationApplicability;
});

test('a national directory applies to every location', function (): void {
    $location = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $location->id, 'categories' => null]);
    $dir = Directory::factory()->create(['scope' => DirectoryScope::National]);

    expect($this->applicability->forLocation($location)->pluck('id'))->toContain($dir->id);
});

test('a town directory applies only to the location that owns the town', function (): void {
    $clifton = Location::factory()->for($this->site)->create(['served_towns' => [['name' => 'Clifton', 'state' => 'NJ']]]);
    $paramus = Location::factory()->for($this->site)->create(['served_towns' => [['name' => 'Paramus', 'state' => 'NJ']]]);
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $clifton->id, 'categories' => null]);
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $paramus->id, 'categories' => null]);

    $townDir = Directory::factory()->geo(DirectoryScope::Town, 'Clifton')->create();

    expect($this->applicability->forLocation($clifton)->pluck('id'))->toContain($townDir->id)
        ->and($this->applicability->forLocation($paramus)->pluck('id'))->not->toContain($townDir->id);
});

test('a state directory applies to in-state locations only', function (): void {
    $nj = Location::factory()->for($this->site)->create();
    $pa = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $nj->id, 'state' => 'NJ', 'categories' => null]);
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $pa->id, 'state' => 'PA', 'categories' => null]);

    $stateDir = Directory::factory()->geo(DirectoryScope::State, 'NJ')->create();

    expect($this->applicability->forLocation($nj)->pluck('id'))->toContain($stateDir->id)
        ->and($this->applicability->forLocation($pa)->pluck('id'))->not->toContain($stateDir->id);
});

test('a county directory stored by geoid matches the location county', function (): void {
    $location = Location::factory()->for($this->site)->create(['home_county_geoid' => '34003']);
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $location->id, 'categories' => null]);

    $inCounty = Directory::factory()->geo(DirectoryScope::County, '34003')->create();
    $otherCounty = Directory::factory()->geo(DirectoryScope::County, '34017')->create();

    $ids = $this->applicability->forLocation($location)->pluck('id');
    expect($ids)->toContain($inCounty->id)->and($ids)->not->toContain($otherCounty->id);
});

test('a sole location owns every town so town directories apply', function (): void {
    $location = Location::factory()->for($this->site)->create(['served_towns' => null]);
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $location->id, 'categories' => null]);
    $townDir = Directory::factory()->geo(DirectoryScope::Town, 'Anywhere')->create();

    expect($this->applicability->forLocation($location)->pluck('id'))->toContain($townDir->id);
});

test('a trade-niche directory is excluded when the location categories do not intersect', function (): void {
    $location = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $location->id, 'categories' => ['roofing']]);

    $plumbingDir = Directory::factory()->create(['trade_categories' => ['plumbing']]);   // location does roofing
    $roofingDir = Directory::factory()->create(['trade_categories' => ['roofing']]);

    $ids = $this->applicability->forLocation($location)->pluck('id');
    expect($ids)->toContain($roofingDir->id)->and($ids)->not->toContain($plumbingDir->id);
});
