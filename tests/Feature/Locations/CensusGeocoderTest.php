<?php

use App\Integrations\Census\CensusGeocoder;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

function geocoder(): CensusGeocoder
{
    return new CensusGeocoder(app(Factory::class), 'https://geocoding.geo.census.gov/geocoder', 'Public_AR_Current', 15);
}

test('it resolves an address to a point (x=lng, y=lat) + matched address', function () {
    Http::fake([
        '*' => Http::response(['result' => ['addressMatches' => [[
            'matchedAddress' => '123 MAIN ST, MAPLEWOOD, NJ, 07040',
            'coordinates' => ['x' => -74.2724, 'y' => 40.7312],
        ]]]]),
    ]);

    $result = geocoder()->geocode('123 Main St, Maplewood NJ');

    expect($result)->not->toBeNull()
        ->and($result->lat)->toBe(40.7312)
        ->and($result->lng)->toBe(-74.2724)
        ->and($result->matchedAddress)->toBe('123 MAIN ST, MAPLEWOOD, NJ, 07040');
});

test('it returns null when there is no address match', function () {
    Http::fake(['*' => Http::response(['result' => ['addressMatches' => []]])]);

    expect(geocoder()->geocode('nowhere at all'))->toBeNull();
});

test('an empty address never calls the network', function () {
    Http::fake(['*' => Http::response([], 500)]);

    expect(geocoder()->geocode('   '))->toBeNull();
    Http::assertNothingSent();
});

test('a transport failure resolves to null, not an exception', function () {
    Http::fake(['*' => Http::response('nope', 503)]);

    expect(geocoder()->geocode('123 Main St'))->toBeNull();
});
