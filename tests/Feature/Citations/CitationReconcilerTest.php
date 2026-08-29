<?php

use App\Citations\CitationReconciler;
use App\Enums\CitationState;
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

test('an applicable directory with no scan status becomes a not_listed gap', function (): void {
    $location = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $location->id, 'categories' => null]);
    $dir = Directory::factory()->create(['scope' => DirectoryScope::National]);

    $written = $this->reconciler->reconcile($location);

    expect($written)->toBe(1);
    $status = CitationStatus::query()->where('location_id', $location->id)->where('directory_id', $dir->id)->first();
    expect($status?->state)->toBe(CitationState::NotListed);
});

test('reconcile never overwrites an existing scan status', function (): void {
    $location = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $location->id, 'categories' => null]);
    $dir = Directory::factory()->create(['scope' => DirectoryScope::National]);
    CitationStatus::factory()->for($this->site)->create([
        'location_id' => $location->id, 'directory_id' => $dir->id, 'state' => CitationState::ListedCorrect,
    ]);

    $written = $this->reconciler->reconcile($location);

    expect($written)->toBe(0)
        ->and(CitationStatus::query()->where('location_id', $location->id)->where('directory_id', $dir->id)->first()->state)
        ->toBe(CitationState::ListedCorrect);
});

test('a one_per_business directory a sibling covers is recorded as covered_by_sibling', function (): void {
    $a = Location::factory()->for($this->site)->create();
    $b = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $a->id, 'categories' => null]);
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $b->id, 'categories' => null]);
    $dir = Directory::factory()->create([
        'scope' => DirectoryScope::National, 'multi_location_policy' => MultiLocationPolicy::OnePerBusiness,
    ]);
    // Sibling A already has a live listing on the one-per-business directory.
    CitationStatus::factory()->for($this->site)->create([
        'location_id' => $a->id, 'directory_id' => $dir->id, 'state' => CitationState::ListedCorrect,
    ]);

    $this->reconciler->reconcile($b);

    $status = CitationStatus::query()->where('location_id', $b->id)->where('directory_id', $dir->id)->first();
    expect($status?->state)->toBe(CitationState::CoveredBySibling);
});
