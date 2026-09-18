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

it('writes ONE paragraph: what we cover, how far it runs, and what to do if you are outside it', function () {
    [$site, $location] = extentSite([
        'Quakertown' => [40.44, -75.34, 14.0, '4201762808'],
        'Yardley, PA' => [40.24, -74.84, 17.0, '4201787032'],
        'Bensalem' => [40.10, -74.94, 22.0, '4201705616'],
        'Souderton' => [40.31, -75.32, 11.0, '4209172664'],
    ]);

    $paragraphs = app(ServiceAreaExtent::class)->paragraph((string) $site->id, $location, 'Doylestown', ['Bucks County']);

    // One paragraph, not a stack of one-line statements.
    expect($paragraphs)->toHaveCount(1);
    expect($paragraphs[0])
        ->toStartWith('From our Doylestown location we serve Bucks County and the communities around it.')
        ->toContain('That territory runs from Quakertown in the north to Bensalem in the south, and from Souderton in the west to Yardley in the east — about 22 miles at its widest.')
        // A single-location tenant never offers an office it does not have.
        ->toContain('call us and we will tell you straight whether we cover it')
        ->not->toContain('one of our other locations');
    // ", PA" is the data model's suffix, not something a reader needs mid-sentence.
    expect($paragraphs[0])->not->toContain('Yardley, PA');
});

it('contrasts the ends of the territory rather than quoting one town at it', function () {
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

    $paragraph = app(ServiceAreaExtent::class)->paragraph((string) $site->id, $location, 'Doylestown', ['Bucks County'])[0];

    // The range is the interesting thing, not one number from one town.
    expect($paragraph)->toContain('The housing varies as much as the distance: about 60% of homes in Yardley were built before 1960, against 20% in Quakertown.')
        ->not->toContain('median home');
});

it('drops only the reach sentence when the footprint is too tight or the base has no coordinates', function () {
    // Two towns, both north-west: fewer than three directions, so the sentence would say less than the
    // list already does.
    [$site, $location] = extentSite([
        'Quakertown' => [40.44, -75.34, 14.0, '4201762808'],
        'Souderton' => [40.33, -75.32, 11.0, '4209172664'],
    ]);
    // The county opening and the invitation still stand; only the reach sentence drops.
    $tight = app(ServiceAreaExtent::class)->paragraph((string) $site->id, $location, 'Doylestown', ['Bucks County'])[0];
    expect($tight)->toContain('we serve Bucks County')
        ->not->toContain('That territory runs');

    $blind = Location::factory()->create(['site_id' => $site->id, 'name' => 'Nowhere', 'lat' => null, 'lng' => null]);
    $noCoords = app(ServiceAreaExtent::class)->paragraph((string) $site->id, $blind, 'Nowhere', ['Bucks County'])[0];
    expect($noCoords)->not->toContain('That territory runs');
});

it('spends a corner town on one direction only, so four directions name four towns', function () {
    [$site, $location] = extentSite([
        // Furthest north AND furthest east — it can only be one of them.
        'Corner' => [40.55, -74.70, 25.0, '4201700001'],
        'Yardley' => [40.24, -74.84, 17.0, '4201787032'],
        'Bensalem' => [40.10, -74.94, 22.0, '4201705616'],
        'Souderton' => [40.31, -75.32, 11.0, '4209172664'],
    ]);

    $paragraph = app(ServiceAreaExtent::class)->paragraph((string) $site->id, $location, 'Doylestown', ['Bucks County'])[0];

    expect(substr_count($paragraph, 'Corner'))->toBe(1)
        ->and($paragraph)->toContain('to Yardley in the east');   // the next town out takes the direction it lost
});

it('offers another office only when the tenant has one', function () {
    [$site, $location] = extentSite([
        'Quakertown' => [40.44, -75.34, 14.0, '4201762808'],
        'Yardley' => [40.24, -74.84, 17.0, '4201787032'],
        'Bensalem' => [40.10, -74.94, 22.0, '4201705616'],
        'Souderton' => [40.31, -75.32, 11.0, '4209172664'],
    ]);

    $paragraph = app(ServiceAreaExtent::class)->paragraph((string) $site->id, $location, 'Doylestown', ['Bucks County'], locationCount: 2)[0];

    expect($paragraph)->toContain('if we cannot reach you from Doylestown, one of our other locations may');
});
