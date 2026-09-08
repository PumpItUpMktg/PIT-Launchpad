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

it('resolveByCoverage: geo hit, unanchored counterpart, anchored counterpart (geo_miss), and uncounted absence', function () {
    $fb = new TownGeoFallback('CoveragePageReportCommand', 'site1');
    $byGeo = ['g1' => 'pageA'];
    $byName = [
        'hoboken' => ['value' => 'pageB', 'anchored' => false], // a page exists, not yet anchored
        'trenton' => ['value' => 'pageC', 'anchored' => true],  // a same-named page IS anchored (to some other geo)
    ];

    expect($fb->resolveByCoverage('g1', 'hoboken', $byGeo, $byName))->toBe('pageA')     // geo hit → by_geo
        ->and($fb->resolveByCoverage('gX', 'hoboken', $byGeo, $byName))->toBe('pageB')  // name hit, unanchored → counterpart_unanchored
        ->and($fb->resolveByCoverage('gY', 'trenton', $byGeo, $byName))->toBe('pageC')  // name hit, anchored → geo_miss
        ->and($fb->resolveByCoverage('gZ', 'nowhere', $byGeo, $byName))->toBeNull();    // no counterpart → uncounted absence

    // The absence is UNCOUNTED — a served town with no page is a legitimate gap, not a fallback.
    expect($fb->counts())->toMatchArray([
        'total' => 3, 'by_geo' => 1, 'by_name' => 2, 'geo_null' => 0, 'counterpart_unanchored' => 1, 'geo_miss' => 1,
    ]);
});

it('coverage-driven report logs counterpart_unanchored and still warns on a geo_miss', function () {
    Log::spy();

    $fb = new TownGeoFallback('CoveragePageReportCommand', 'siteZ');
    $fb->resolveByCoverage('g1', 'a', ['g1' => 1], []);                                   // by_geo
    $fb->resolveByCoverage('gX', 'b', ['g1' => 1], ['b' => ['value' => 2, 'anchored' => false]]); // counterpart_unanchored
    $fb->resolveByCoverage('gY', 'c', ['g1' => 1], ['c' => ['value' => 3, 'anchored' => true]]);  // geo_miss
    $fb->report();

    Log::shouldHaveReceived('info')->once()->withArgs(fn (string $m, array $c): bool => $m === 'towngeo.fallback'
        && $c['total'] === 3 && $c['by_geo'] === 1 && $c['by_name'] === 2
        && $c['counterpart_unanchored'] === 1 && $c['geo_miss'] === 1);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $m, array $c): bool => str_contains($m, 'geo_miss') && $c['geo_miss'] === 1);
});
