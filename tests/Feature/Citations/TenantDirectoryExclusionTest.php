<?php

use App\Citations\CitationApplicability;
use App\Enums\DirectoryScope;
use App\Models\Directory;
use App\Models\Location;
use App\Models\LocationNapProfile;
use App\Models\Site;
use App\Models\TenantDirectoryExclusion;
use App\Support\CurrentSite;

beforeEach(function (): void {
    $this->site = Site::factory()->create();
    CurrentSite::set($this->site->id);
    $this->applicability = new CitationApplicability;
});

test('a tenant exclusion removes a directory from eligibility for every location the tenant owns', function (): void {
    $a = Location::factory()->for($this->site)->create();
    $b = Location::factory()->for($this->site)->create();
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $a->id, 'categories' => null]);
    LocationNapProfile::factory()->for($this->site)->create(['location_id' => $b->id, 'categories' => null]);
    $dir = Directory::factory()->create(['scope' => DirectoryScope::National]);

    // Applies to both locations before exclusion.
    expect($this->applicability->forLocation($a)->pluck('id'))->toContain($dir->id)
        ->and($this->applicability->forLocation($b)->pluck('id'))->toContain($dir->id);

    // Excluding at the tenant level drops it for EVERY location the tenant owns.
    TenantDirectoryExclusion::factory()->for($this->site)->create(['directory_id' => $dir->id]);

    expect($this->applicability->forLocation($a)->pluck('id'))->not->toContain($dir->id)
        ->and($this->applicability->forLocation($b)->pluck('id'))->not->toContain($dir->id);
});
