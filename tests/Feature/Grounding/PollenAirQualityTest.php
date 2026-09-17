<?php

use App\Local\Grounding\AirQualityProvider;
use App\Local\Grounding\PollenProvider;
use App\Models\Location;
use App\Models\Site;
use Illuminate\Support\Facades\Http;

function groundingLocation(): Location
{
    $site = Site::factory()->create();

    return Location::factory()->create(['site_id' => $site->id, 'name' => 'Doylestown', 'lat' => 40.31, 'lng' => -75.13]);
}

/** The Pollen API's documented forecast shape, trimmed to the fields the provider reads. */
function pollenResponse(): array
{
    return ['regionCode' => 'US', 'dailyInfo' => [[
        'date' => ['year' => 2026, 'month' => 9, 'day' => 17],
        'pollenTypeInfo' => [
            ['code' => 'GRASS', 'displayName' => 'Grass', 'inSeason' => false],
            ['code' => 'TREE', 'displayName' => 'Tree', 'inSeason' => false],
            ['code' => 'WEED', 'displayName' => 'Weed', 'inSeason' => true],
        ],
        'plantInfo' => [
            ['code' => 'RAGWEED', 'displayName' => 'Ragweed', 'inSeason' => true,
                'plantDescription' => ['type' => 'WEED', 'family' => 'Asteraceae', 'season' => 'Late summer, early fall']],
            ['code' => 'GRAMINALES', 'displayName' => 'Grasses', 'inSeason' => false,
                'plantDescription' => ['type' => 'GRASS', 'season' => 'Spring, summer']],
            // No description → nothing durable to say, so it is skipped rather than guessed at.
            ['code' => 'OAK', 'displayName' => 'Oak', 'inSeason' => false],
        ],
    ]]];
}

it('keeps the pollen SEASONS and throws the forecast away', function () {
    config(['services.google.maps_api_key' => 'test-key']);
    Http::fake(['*pollen.googleapis.com*' => Http::response(pollenResponse())]);

    $result = app(PollenProvider::class)->fetch(groundingLocation());

    expect($result['facts'])->toBe([
        'Ragweed pollen is seasonal here: late summer, early fall.',
        'Grasses pollen is seasonal here: spring, summer.',
        'The pollen types tracked for this area are grass, tree, weed.',
    ]);
    // A page outlives the forecast, so no index value, no "today", no "high".
    expect(implode(' ', $result['facts']))->not->toContain('today')->not->toContain('High');
});

it('states air quality as a dated week-long average, never as how the air is now', function () {
    config(['services.google.maps_api_key' => 'test-key']);
    Http::fake(['*airquality.googleapis.com*' => Http::response(['hoursInfo' => [
        ['dateTime' => '2026-09-16T10:00:00Z', 'indexes' => [['code' => 'uaqi', 'displayName' => 'Universal AQI', 'aqi' => 40, 'dominantPollutant' => 'pm25']]],
        ['dateTime' => '2026-09-16T11:00:00Z', 'indexes' => [['code' => 'uaqi', 'displayName' => 'Universal AQI', 'aqi' => 50, 'dominantPollutant' => 'pm25']]],
        ['dateTime' => '2026-09-16T12:00:00Z', 'indexes' => [['code' => 'uaqi', 'displayName' => 'Universal AQI', 'aqi' => 60, 'dominantPollutant' => 'o3']]],
    ]])]);

    $facts = app(AirQualityProvider::class)->fetch(groundingLocation())['facts'];

    expect($facts[0])->toContain('In the week to ')
        ->and($facts[0])->toContain('universal aqi at this location averaged 50')
        ->and($facts[1])->toBe('Over that week the dominant pollutant was most often PM25.');
    // The claim is about a window that has passed, not about the air a reader is breathing.
    expect(implode(' ', $facts))->not->toContain('air quality here is')->not->toContain('currently');
});

it('says nothing without a key, and nothing when the call fails', function () {
    config(['services.google.maps_api_key' => '']);
    Http::fake();
    $location = groundingLocation();

    expect(app(PollenProvider::class)->fetch($location)['facts'])->toBe([])
        ->and(app(AirQualityProvider::class)->fetch($location)['facts'])->toBe([]);
    Http::assertNothingSent();

    config(['services.google.maps_api_key' => 'test-key']);
    Http::fake([
        '*pollen.googleapis.com*' => Http::response(['error' => ['code' => 403]], 403),
        '*airquality.googleapis.com*' => Http::response(['error' => ['code' => 403]], 403),
    ]);

    expect(app(PollenProvider::class)->fetch($location)['facts'])->toBe([])
        ->and(app(AirQualityProvider::class)->fetch($location)['facts'])->toBe([]);
});
