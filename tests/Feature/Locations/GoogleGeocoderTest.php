<?php

use App\Integrations\Census\GoogleGeocoder;
use App\Integrations\Census\MockCensusGeocoder;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

function googleGeocoder(string $key = 'test-key', ?MockCensusGeocoder $fallback = null): GoogleGeocoder
{
    return new GoogleGeocoder(app(Factory::class), $key, 'https://maps.googleapis.com/maps/api/geocode/json', $fallback, 15);
}

test('it resolves an address Google returns (the Trooper case Census missed)', function () {
    Http::fake(['*' => Http::response(['status' => 'OK', 'results' => [[
        'formatted_address' => '2753 W Main St, Norristown, PA 19403, USA',
        'geometry' => ['location' => ['lat' => 40.1361, 'lng' => -75.4123]],
    ]]])]);

    $result = googleGeocoder()->geocode('2753 W Main St, Trooper PA 19403');

    expect($result)->not->toBeNull()
        ->and($result->lat)->toBe(40.1361)
        ->and($result->lng)->toBe(-75.4123)
        ->and($result->matchedAddress)->toContain('Norristown'); // city resolves, point is what matters
});

test('ZERO_RESULTS falls back to the Census geocoder', function () {
    Http::fake(['*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []])]);

    $result = googleGeocoder(fallback: new MockCensusGeocoder(40.5, -74.5))->geocode('somewhere');

    expect($result->lat)->toBe(40.5)->and($result->lng)->toBe(-74.5);
});

test('with no key it uses the fallback and never calls Google', function () {
    Http::fake(['*' => Http::response([], 500)]);

    $result = googleGeocoder(key: '', fallback: new MockCensusGeocoder(41.0, -75.0))->geocode('123 Main St');

    expect($result->lat)->toBe(41.0);
    Http::assertNothingSent();
});

test('a transport failure degrades to the fallback', function () {
    Http::fake(['*' => Http::response('nope', 503)]);

    $result = googleGeocoder(fallback: new MockCensusGeocoder(42.0, -76.0))->geocode('123 Main St');

    expect($result->lat)->toBe(42.0);
});
