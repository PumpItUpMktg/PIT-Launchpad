<?php

use App\Locations\TownGeoFallback;
use Illuminate\Support\Facades\Log;

it('prefers geo, falls back to name, and counts the three paths apart', function () {
    $fb = new TownGeoFallback('X', 'site1');
    $byGeo = ['g1' => 'ownerA'];
    $byName = ['hoboken' => 'ownerB', 'trenton' => 'ownerC'];

    expect($fb->resolve('g1', 'hoboken', $byGeo, $byName))->toBe('ownerA')    // geo hit
        ->and($fb->resolve(null, 'hoboken', $byGeo, $byName))->toBe('ownerB')  // geo null → name
        ->and($fb->resolve('g9', 'trenton', $byGeo, $byName))->toBe('ownerC')  // geo present but miss → name
        ->and($fb->resolve(null, 'nowhere', $byGeo, $byName))->toBeNull();     // neither

    expect($fb->counts())->toMatchArray([
        'total' => 4, 'by_geo' => 1, 'by_name' => 3, 'geo_null' => 2, 'geo_miss' => 1,
    ]);
});

it('logs one aggregate tripwire line per run, with the total, and warns separately on a geo_miss', function () {
    Log::spy();

    $fb = new TownGeoFallback('TownLocationAssigner', 'siteZ');
    $fb->resolve('g1', 'a', ['g1' => 1], []);              // by_geo
    $fb->resolve(null, 'b', ['g1' => 1], ['b' => 2]);      // geo_null
    $fb->resolve('gX', 'c', ['g1' => 1], ['c' => 3]);      // geo_miss
    $fb->report();

    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $m, array $c): bool => $m === 'towngeo.fallback'
        && $c['total'] === 3 && $c['by_geo'] === 1 && $c['by_name'] === 2 && $c['geo_null'] === 1 && $c['geo_miss'] === 1);

    // geo_miss (anchor/consumer disagree) gets its own warning, not just a field in the aggregate.
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m, array $c): bool => str_contains($m, 'geo_miss') && $c['geo_miss'] === 1);
});

it('is silent when there were no town pages to resolve', function () {
    Log::spy();
    (new TownGeoFallback('X', 's'))->report();
    Log::shouldNotHaveReceived('info');
});
