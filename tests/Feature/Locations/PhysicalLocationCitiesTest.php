<?php

use App\Locations\PhysicalLocationCities;
use App\Models\Location;
use App\Models\Site;

it('keys a location by its GBP city and matches a coverage town by name + state', function () {
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id, 'address_components' => [
        ['types' => ['locality'], 'long_name' => 'Hoboken'],
        ['types' => ['administrative_area_level_1'], 'short_name' => 'NJ'],
    ]]);

    $svc = app(PhysicalLocationCities::class);
    $set = $svc->forSite($site);

    expect($svc->matches('Hoboken', 'NJ', $set))->toBeTrue()
        ->and($svc->matches('hoboken', null, $set))->toBeTrue()   // unknown state on the town → match (single-footprint)
        ->and($svc->matches('Hoboken', 'PA', $set))->toBeFalse()  // state disagreement → a real different town
        ->and($svc->matches('Weehawken', 'NJ', $set))->toBeFalse();
});

it('falls back to the location name when no GBP city is parsed', function () {
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id, 'name' => 'Trenton', 'address_components' => null]);

    expect(app(PhysicalLocationCities::class)->isLocationCity($site, 'Trenton', null))->toBeTrue();
});

it('scopes to one site — another tenant\'s location cities never match', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    Location::factory()->create(['site_id' => $b->id, 'address_components' => [
        ['types' => ['locality'], 'long_name' => 'Montclair'],
        ['types' => ['administrative_area_level_1'], 'short_name' => 'NJ'],
    ]]);

    expect(app(PhysicalLocationCities::class)->isLocationCity($a, 'Montclair', 'NJ'))->toBeFalse();
});
