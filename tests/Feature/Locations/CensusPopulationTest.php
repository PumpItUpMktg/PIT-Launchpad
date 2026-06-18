<?php

use App\Integrations\Census\CensusPopulation;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Http as HttpFacade;

function population(string $key = 'test-key'): CensusPopulation
{
    return new CensusPopulation(app(Http::class), app(Cache::class), $key, '2022');
}

test('it joins ACS population to the 10-digit county-subdivision GEOID', function () {
    HttpFacade::fake(['api.census.gov/*' => HttpFacade::response([
        ['NAME', 'B01003_001E', 'state', 'county', 'county subdivision'],
        ['Belleville township, Essex County, New Jersey', '36000', '34', '013', '05580'],
        ['Montclair township, Essex County, New Jersey', '40000', '34', '013', '51210'],
        ['Essex Fells borough, Essex County, New Jersey', '2200', '34', '013', '21840'],
    ])]);

    $pop = population()->forCounty('34', '013');

    expect($pop)->toBe([
        '3401305580' => 36000, // state+county+cousub concatenated
        '3401351210' => 40000,
        '3401321840' => 2200,
    ]);
});

test('with no key it degrades to empty and never calls the API', function () {
    HttpFacade::fake(['*' => HttpFacade::response('should not be called', 500)]);

    expect(population(key: '')->forCounty('34', '013'))->toBe([]);
    HttpFacade::assertNothingSent();
});

test('a missing-key HTML / malformed response degrades to empty', function () {
    HttpFacade::fake(['api.census.gov/*' => HttpFacade::response('<html>Missing Key</html>')]);

    expect(population()->forCounty('34', '013'))->toBe([]);
});
