<?php

use App\Citations\CitationReconciler;
use App\Enums\CitationPresence;
use App\Enums\DirectoryScope;
use App\Enums\MultiLocationPolicy;
use App\Models\CitationStatus;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Support\CurrentSite;

beforeEach(function (): void {
    $this->site = Site::factory()->create();
    CurrentSite::set($this->site->id);
    $this->reconciler = new CitationReconciler;
});

test('an applicable directory with no scan status becomes an absent gap', function (): void {
    $location = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $location->id, 'categories' => null]);
    $dir = Directory::factory()->create(['scope' => DirectoryScope::National]);

    $written = $this->reconciler->reconcile($location);

    expect($written)->toBe(1);
    $status = CitationStatus::query()->where('location_id', $location->id)->where('directory_id', $dir->id)->first();
    expect($status?->presence)->toBe(CitationPresence::Absent);
});

test('reconcile never overwrites an existing scan status', function (): void {
    $location = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $location->id, 'categories' => null]);
    $dir = Directory::factory()->create(['scope' => DirectoryScope::National]);
    CitationStatus::factory()->for($this->site)->create([
        'location_id' => $location->id, 'directory_id' => $dir->id, 'presence' => CitationPresence::PresentMatch,
    ]);

    $written = $this->reconciler->reconcile($location);

    expect($written)->toBe(0)
        ->and(CitationStatus::query()->where('location_id', $location->id)->where('directory_id', $dir->id)->first()->presence)
        ->toBe(CitationPresence::PresentMatch);
});

test('a one_per_business directory a sibling covers is recorded as covered_by_sibling', function (): void {
    $a = Location::factory()->for($this->site)->create();
    $b = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $a->id, 'categories' => null]);
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $b->id, 'categories' => null]);
    $dir = Directory::factory()->create([
        'scope' => DirectoryScope::National, 'multi_location_policy' => MultiLocationPolicy::OnePerBusiness,
    ]);
    // Sibling A already has a correct listing on the one-per-business directory.
    CitationStatus::factory()->for($this->site)->create([
        'location_id' => $a->id, 'directory_id' => $dir->id, 'presence' => CitationPresence::PresentMatch,
    ]);

    $this->reconciler->reconcile($b);

    $status = CitationStatus::query()->where('location_id', $b->id)->where('directory_id', $dir->id)->first();
    expect($status?->covered_by_sibling)->toBeTrue()
        ->and($status?->presence)->toBe(CitationPresence::Absent);
});
