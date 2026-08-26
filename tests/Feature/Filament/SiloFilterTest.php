<?php

use App\Filament\Support\SiloFilter;
use App\Models\Silo;
use App\Models\Site;
use App\Support\CurrentSite;

afterEach(fn () => CurrentSite::clear());

it('scopes silo filter options to the given tenant', function () {
    $tenantA = Site::factory()->create();
    $tenantB = Site::factory()->create();
    $repair = Silo::factory()->create(['site_id' => $tenantA->id, 'name' => 'Repair']);
    Silo::factory()->create(['site_id' => $tenantB->id, 'name' => 'Another tenant silo']);

    // Only the selected tenant's silo — never the other tenant's.
    expect(SiloFilter::optionsForTenant($tenantA->id))->toBe([$repair->id => 'Repair']);
});

it('returns no silo options when no tenant is selected', function () {
    Silo::factory()->create();

    expect(SiloFilter::optionsForTenant(null))->toBe([])
        ->and(SiloFilter::optionsForTenant(''))->toBe([]);
});
