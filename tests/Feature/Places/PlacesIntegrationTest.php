<?php

use App\Integrations\Places\GooglePlacesClient;
use App\Integrations\Places\MockPlacesProvider;
use App\Integrations\Places\PlaceHours;
use App\Integrations\Places\PlaceQuery;
use App\Integrations\Places\PlacesProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

function placesClient(): GooglePlacesClient
{
    return new GooglePlacesClient(app(Factory::class), 'test-key');
}

it('maps Google opening_hours periods to the per-day shape', function () {
    $hours = PlaceHours::fromGoogle(['periods' => [
        ['open' => ['day' => 1, 'time' => '0800'], 'close' => ['day' => 1, 'time' => '1700']], // mon
        ['open' => ['day' => 0, 'time' => '1000'], 'close' => ['day' => 0, 'time' => '1400']], // sun
    ]]);

    expect($hours['mon'])->toBe(['open' => '08:00', 'close' => '17:00'])
        ->and($hours['sun'])->toBe(['open' => '10:00', 'close' => '14:00'])
        ->and($hours['tue'])->toBe('closed') // missing → closed
        ->and(array_keys($hours))->toBe(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']);
});

it('maps Google 24-hour days to "24h", not a fabricated 00:00–23:59', function () {
    // A per-day open with no close = 24 hours.
    $perDay = PlaceHours::fromGoogle(['periods' => [
        ['open' => ['day' => 1, 'time' => '0000']], // mon, no close
        ['open' => ['day' => 2, 'time' => '0800'], 'close' => ['day' => 2, 'time' => '1700']],
    ]]);
    expect($perDay['mon'])->toBe('24h')
        ->and($perDay['tue'])->toBe(['open' => '08:00', 'close' => '17:00']);

    // Open 24/7: a single period, day 0, time 0000, no close → every day 24h.
    $always = PlaceHours::fromGoogle(['periods' => [['open' => ['day' => 0, 'time' => '0000']]]]);
    expect(array_values(array_unique($always)))->toBe(['24h']);
});

it('normalizes a Maps URL or a plain name into a search query', function () {
    expect(PlaceQuery::normalize('https://www.google.com/maps/place/Apex+Plumbing/@30.2,-97.7,17z'))->toBe('Apex Plumbing')
        ->and(PlaceQuery::normalize('Apex Plumbing Austin'))->toBe('Apex Plumbing Austin')
        ->and(PlaceQuery::normalize('https://maps.google.com/?q=Apex%20Plumbing'))->toBe('Apex Plumbing');
});

it('searches and resolves details against the live Places API (faked)', function () {
    Http::fake([
        '*/textsearch/json*' => Http::response(['results' => [
            ['place_id' => 'PID1', 'name' => 'Apex Plumbing', 'formatted_address' => '500 W 2nd St, Austin'],
        ]]),
        '*/details/json*' => Http::response(['result' => [
            'place_id' => 'PID1',
            'name' => 'Apex Plumbing',
            'formatted_address' => '500 W 2nd St, Austin, TX 78701, USA',
            'address_components' => [['long_name' => 'Austin', 'types' => ['locality']]],
            'international_phone_number' => '+1 512-555-0142',
            'opening_hours' => ['periods' => [['open' => ['day' => 1, 'time' => '0800'], 'close' => ['day' => 1, 'time' => '1700']]]],
            'geometry' => ['location' => ['lat' => 30.267153, 'lng' => -97.7430608]],
            'url' => 'https://maps.google.com/?cid=123',
            'website' => 'https://apex.example',
        ]]),
    ]);

    $candidates = placesClient()->search('Apex Plumbing Austin');
    expect($candidates)->toHaveCount(1)->and($candidates[0]->placeId)->toBe('PID1');

    $details = placesClient()->details('PID1');
    expect($details->phone)->toBe('+1 512-555-0142')
        ->and($details->lat)->toBe(30.267153)
        ->and($details->gbpUrl)->toBe('https://maps.google.com/?cid=123')
        ->and($details->hours['mon'])->toBe(['open' => '08:00', 'close' => '17:00']);
});

it('reports REQUEST_DENIED as the Places-API-not-enabled signal', function () {
    Http::fake(['*/findplacefromtext/json*' => Http::response(['status' => 'REQUEST_DENIED', 'error_message' => 'This API project is not authorized.'])]);

    $status = placesClient()->smokeTest();

    expect($status->ok)->toBeFalse()
        ->and($status->message)->toContain('enable the Places API');
});

it('the mock provider returns a fully populated place', function () {
    $mock = new MockPlacesProvider;
    $details = $mock->details(MockPlacesProvider::PLACE_ID);

    expect($details->name)->toContain('Apex')
        ->and($details->hours['mon'])->toBe(['open' => '08:00', 'close' => '17:00'])
        ->and($mock->smokeTest()->ok)->toBeTrue();
});

it('binds the real Google client by default', function () {
    expect(app(PlacesProvider::class))->toBeInstanceOf(GooglePlacesClient::class);
});
