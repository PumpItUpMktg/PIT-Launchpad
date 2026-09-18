<?php

use App\Models\CensusHousing;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Site;
use App\Publishing\Blocks\ServiceAreaExtent;

/** A location with towns spread in every direction — the shape a real service area has. */
function extentSite(array $towns): array
{
    $site = Site::factory()->create(['domain_url' => 'https://spg.example']);
    $location = Location::factory()->create(['site_id' => $site->id, 'name' => 'Doylestown', 'lat' => 40.31, 'lng' => -75.13]);

    foreach ($towns as $name => [$lat, $lng, $miles, $geoId]) {
        CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => $geoId, 'name' => $name,
            'lat' => $lat, 'lng' => $lng, 'distance_miles' => $miles, 'population' => 10000,
            'source_location_ids' => [$location->id]]);
    }

    return [$site, $location];
}

it('names the furthest town each way and the widest reach, from the coverage we already claim', function () {
    [$site, $location] = extentSite([
        'Quakertown' => [40.44, -75.34, 14.0, '4201762808'],
        'Yardley, PA' => [40.24, -74.84, 17.0, '4201787032'],
        'Bensalem' => [40.10, -74.94, 22.0, '4201705616'],
        'Souderton' => [40.31, -75.32, 11.0, '4209172664'],
    ]);

    $sentences = app(ServiceAreaExtent::class)->sentences((string) $site->id, $location, 'Doylestown');

    expect($sentences[0])
        ->toBe('From Doylestown that reaches north to Quakertown, east to Yardley, south to Bensalem and west to Souderton — about 22 miles at the widest point.');
    // ", PA" is the data model's suffix, not something a reader needs mid-sentence.
    expect($sentences[0])->not->toContain('Yardley, PA');
});

it('adds at most one local detail, and only a distinguishing one', function () {
    [$site, $location] = extentSite([
        'Quakertown' => [40.44, -75.34, 14.0, '4201762808'],
        'Yardley' => [40.24, -74.84, 17.0, '4201787032'],
        'Bensalem' => [40.10, -74.94, 22.0, '4201705616'],
        'Souderton' => [40.31, -75.32, 11.0, '4209172664'],
    ]);

    // The first named town carries only a median-year fact — true of every town, so it is passed over…
    CensusHousing::query()->create(['geo_id' => '4201762808', 'name' => 'Quakertown', 'acs_year' => '2022',
        'median_year_built' => 1975, 'occupied_units' => 1000, 'owner_occupied_units' => 600,
        'total_units' => 1000, 'single_family_units' => 600, 'pre_1960_units' => 200]);
    // …in favour of one that actually distinguishes an end of the service area.
    CensusHousing::query()->create(['geo_id' => '4201787032', 'name' => 'Yardley', 'acs_year' => '2022',
        'median_year_built' => 1948, 'occupied_units' => 1000, 'owner_occupied_units' => 820,
        'total_units' => 1100, 'single_family_units' => 990, 'pre_1960_units' => 660]);

    $sentences = app(ServiceAreaExtent::class)->sentences((string) $site->id, $location, 'Doylestown');

    expect($sentences)->toHaveCount(2)
        ->and($sentences[1])->toBe('About 60% of the housing stock in Yardley was built before 1960.')
        ->and($sentences[1])->not->toContain('median home');
});

it('says nothing when the footprint is too tight to describe by compass, or the base has no coordinates', function () {
    // Two towns, both north-west: fewer than three directions, so the sentence would say less than the
    // list already does.
    [$site, $location] = extentSite([
        'Quakertown' => [40.44, -75.34, 14.0, '4201762808'],
        'Souderton' => [40.33, -75.32, 11.0, '4209172664'],
    ]);
    expect(app(ServiceAreaExtent::class)->sentences((string) $site->id, $location, 'Doylestown'))->toBe([]);

    $blind = Location::factory()->create(['site_id' => $site->id, 'name' => 'Nowhere', 'lat' => null, 'lng' => null]);
    expect(app(ServiceAreaExtent::class)->sentences((string) $site->id, $blind, 'Nowhere'))->toBe([]);
});

it('spends a corner town on one direction only, so four directions name four towns', function () {
    [$site, $location] = extentSite([
        // Furthest north AND furthest east — it can only be one of them.
        'Corner' => [40.55, -74.70, 25.0, '4201700001'],
        'Yardley' => [40.24, -74.84, 17.0, '4201787032'],
        'Bensalem' => [40.10, -74.94, 22.0, '4201705616'],
        'Souderton' => [40.31, -75.32, 11.0, '4209172664'],
    ]);

    $sentence = app(ServiceAreaExtent::class)->sentences((string) $site->id, $location, 'Doylestown')[0];

    expect(substr_count($sentence, 'Corner'))->toBe(1)
        ->and($sentence)->toContain('east to Yardley');   // the next town out takes the direction it lost
});
