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

it('reads a listing\'s public profile: rating, ratings, types, photo count, status, position', function () {
    Http::fake([
        '*/details/json*' => Http::response(['result' => [
            'place_id' => 'PID1', 'name' => 'Apex Plumbing', 'rating' => 4.8, 'user_ratings_total' => 212,
            'types' => ['plumber', 'point_of_interest', 'establishment'], 'photos' => [['photo_reference' => 'a'], ['photo_reference' => 'b']],
            'business_status' => 'OPERATIONAL', 'geometry' => ['location' => ['lat' => 30.267153, 'lng' => -97.7430608]],
            'url' => 'https://maps.google.com/?cid=123', 'website' => 'https://apex.example',
        ]]),
    ]);

    $p = placesClient()->profile('PID1');

    expect($p->rating)->toBe(4.8)->and($p->ratingsTotal)->toBe(212)->and($p->primaryType())->toBe('plumber')
        ->and($p->photoCount)->toBe(2)->and($p->businessStatus)->toBe('OPERATIONAL')->and($p->lat)->toBe(30.267153)
        ->and($p->website)->toBe('https://apex.example');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'fields=place_id%2Cname%2Crating%2Cuser_ratings_total%2Ctypes%2Cphotos%2Cbusiness_status'));
});

it('reads the listing\'s Google reviews with the profile — verbatim, rating-only entries dropped', function () {
    Http::fake([
        '*/details/json*' => Http::response(['result' => [
            'place_id' => 'PID1', 'name' => 'Apex Plumbing', 'rating' => 4.8, 'user_ratings_total' => 212,
            'reviews' => [
                ['author_name' => 'Dana R.', 'author_url' => 'https://www.google.com/maps/contrib/1', 'rating' => 5, 'text' => 'Fixed our sump the same day.', 'relative_time_description' => 'a month ago', 'time' => 1760000000],
                ['author_name' => 'Lee', 'rating' => 4, 'text' => '', 'relative_time_description' => '2 months ago', 'time' => 1750000000],
                ['author_name' => 'Pat', 'rating' => 2, 'text' => 'Late, but the work held up.', 'relative_time_description' => 'a year ago', 'time' => 1720000000],
            ],
        ]]),
    ]);

    $p = placesClient()->profile('PID1');

    expect($p->reviews)->toHaveCount(2)
        ->and($p->reviews[0]->authorName)->toBe('Dana R.')->and($p->reviews[0]->rating)->toBe(5)
        ->and($p->reviews[0]->text)->toBe('Fixed our sump the same day.')->and($p->reviews[0]->relativeTime)->toBe('a month ago')
        ->and($p->reviews[0]->time)->toBe(1760000000)->and($p->reviews[0]->authorUrl)->toBe('https://www.google.com/maps/contrib/1')
        ->and($p->reviews[1]->rating)->toBe(2);   // Google's pick ships as given — a low one included
    Http::assertSent(fn ($request) => str_contains($request->url(), '%2Creviews'));
});
