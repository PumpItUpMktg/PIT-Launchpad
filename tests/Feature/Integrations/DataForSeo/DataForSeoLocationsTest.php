<?php

use App\Integrations\DataForSeo\DataForSeoClient;
use App\Integrations\DataForSeo\DataForSeoLocations;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/** A trimmed Google Ads locations catalog (the shape DataForSEO returns). */
function fakeLocationsCatalog(): void
{
    Http::fake([
        '*/keywords_data/google_ads/locations*' => Http::response(['status_code' => 20000, 'tasks' => [['status_code' => 20000, 'result' => [
            ['location_code' => 1023191, 'location_name' => 'New York, NY, United States', 'location_type' => 'DMA Region'],
            ['location_code' => 1022000, 'location_name' => 'Philadelphia, PA, United States', 'location_type' => 'DMA Region'],
            ['location_code' => 1021515, 'location_name' => 'Allentown-Bethlehem, PA, United States', 'location_type' => 'DMA Region'],
            ['location_code' => 21138, 'location_name' => 'New Jersey,United States', 'location_type' => 'State'],
            ['location_code' => 21152, 'location_name' => 'Pennsylvania,United States', 'location_type' => 'State'],
            ['location_code' => 1014221, 'location_name' => 'New York,New York,United States', 'location_type' => 'City'],
        ]]]]),
    ]);
}

function locations(): DataForSeoLocations
{
    $client = new DataForSeoClient(app(Factory::class), 'x', 'y', 'https://api.dataforseo.com', 30);

    return new DataForSeoLocations($client, app(Cache::class), 'US');
}

test('it resolves a City,ST metro to the DMA Region location_code', function () {
    fakeLocationsCatalog();

    expect(locations()->resolve('New York,NY,United States'))->toBe(1023191)
        ->and(locations()->resolve('Philadelphia,PA,United States'))->toBe(1022000);
});

test('it resolves a partial city name to the containing DMA (Allentown → Allentown-Bethlehem)', function () {
    fakeLocationsCatalog();

    expect(locations()->resolve('Allentown,PA,United States'))->toBe(1021515);
});

test('it resolves a full state name to the State location_code', function () {
    fakeLocationsCatalog();

    expect(locations()->resolve('New Jersey,United States'))->toBe(21138)
        ->and(locations()->resolve('Pennsylvania,United States'))->toBe(21152);
});

test('a City,ST whose DMA is not in the catalog falls back to the postal state code', function () {
    // No Allentown DMA in this catalog, but Pennsylvania (state) is present.
    Http::fake([
        '*/keywords_data/google_ads/locations*' => Http::response(['status_code' => 20000, 'tasks' => [['status_code' => 20000, 'result' => [
            ['location_code' => 1022000, 'location_name' => 'Philadelphia, PA, United States', 'location_type' => 'DMA Region'],
            ['location_code' => 21152, 'location_name' => 'Pennsylvania,United States', 'location_type' => 'State'],
        ]]]]),
    ]);

    expect(locations()->resolve('Allentown,PA,United States'))->toBe(21152); // PA state, not zero/null
});

test('an unknown metro with no resolvable state is null', function () {
    fakeLocationsCatalog();

    expect(locations()->resolve('Nowhere,ZZ,United States'))->toBeNull();
});

test('the catalog is fetched once and cached across resolves', function () {
    fakeLocationsCatalog();
    $svc = locations();

    $svc->resolve('New York,NY,United States');
    $svc->resolve('Philadelphia,PA,United States');
    $svc->resolve('New Jersey,United States');

    Http::assertSentCount(1); // one locations fetch, then served from cache
});
