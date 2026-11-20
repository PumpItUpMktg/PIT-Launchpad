<?php

use App\ContentEngine\Geography\Footprint;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Market;
use App\Models\Site;

function geoAddress(string $city, string $stateName, string $state): array
{
    return [
        ['long_name' => $city, 'short_name' => $city, 'types' => ['locality', 'political']],
        ['long_name' => $stateName, 'short_name' => $state, 'types' => ['administrative_area_level_1', 'political']],
    ];
}

it('reads the footprint from the tenant\'s own records — offices, corporate state, markets, coverage towns — and rings it with the bordering states', function () {
    $site = Site::factory()->create(['corporate_state' => 'NJ']);
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Trooper office', 'address_components' => geoAddress('Trooper', 'Pennsylvania', 'PA')]);
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Montclair office', 'address_components' => geoAddress('Montclair', 'New Jersey', 'NJ')]);
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Fallston', 'region' => 'MD']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Phoenixville', 'state' => 'PA', 'distance_miles' => 4.0]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Trooper', 'state' => 'PA', 'distance_miles' => 0.0]); // same town as the office — once

    $fp = Footprint::forSite($site);

    expect($fp->states)->toBe(['MD', 'NJ', 'PA'])
        ->and($fp->ring)->toBe(['DC', 'DE', 'NY', 'OH', 'VA', 'WV'])
        ->and($fp->towns)->toBe(['Trooper PA', 'Montclair NJ', 'Fallston MD', 'Phoenixville PA'])
        ->and($fp->promptLine())->toContain('Maryland (MD), New Jersey (NJ), Pennsylvania (PA)')->toContain('Trooper PA; Montclair NJ');
});

it('has no ring when the ring setting is none, and is unknown for a tenant with no geography on record', function () {
    config(['launchpad.reactive.geography.ring' => 'none']);
    $site = Site::factory()->create(['corporate_state' => 'PA']);
    expect(Footprint::forSite($site)->ring)->toBe([])
        ->and(Footprint::forSite($site)->states)->toBe(['PA']);

    $bare = Site::factory()->create(['corporate_state' => null]);
    expect(Footprint::forSite($bare)->isKnown())->toBeFalse();
});
