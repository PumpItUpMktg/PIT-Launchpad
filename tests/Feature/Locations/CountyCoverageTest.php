<?php

use App\Enums\MunicipalityType;
use App\Integrations\Census\CensusPopulation;
use App\Integrations\Census\County;
use App\Integrations\Census\MockMunicipalityGazetteer;
use App\Integrations\Census\Municipality;
use App\Locations\CountyCoverage;
use App\Locations\CoverageResult;
use App\Models\Location;
use App\Models\Site;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Http as HttpFacade;

/** Essex County, NJ (34013) with three subdivisions of varying size. */
function essexGazetteer(): MockMunicipalityGazetteer
{
    return new MockMunicipalityGazetteer(
        municipalities: [],
        counties: [new County('34013', 'Essex', '34', '013')],
        subdivisions: [
            '34:013' => [
                new Municipality('3401305580', 'Belleville Twp', MunicipalityType::CountySubdivision, 'NJ', 40.79, -74.15),
                new Municipality('3401351210', 'Montclair Twp', MunicipalityType::CountySubdivision, 'NJ', 40.82, -74.21),
                new Municipality('3401321840', 'Essex Fells Boro', MunicipalityType::CountySubdivision, 'NJ', 40.82, -74.28),
            ],
        ],
    );
}

/** A CensusPopulation backed by an Http::fake ACS response keyed to the 10-digit GEOIDs. */
function essexPopulation(): CensusPopulation
{
    HttpFacade::fake(['api.census.gov/*' => HttpFacade::response([
        ['NAME', 'B01003_001E', 'state', 'county', 'county subdivision'],
        ['Belleville township', '36000', '34', '013', '05580'],   // Large (> 25k)
        ['Montclair township', '18000', '34', '013', '51210'],     // Medium (15k–25k)
        ['Essex Fells borough', '2200', '34', '013', '21840'],     // Small (< 15k)
    ])]);

    return new CensusPopulation(app(Http::class), app(Cache::class), 'test-key', '2022');
}

test('it enumerates a county subdivisions and joins ACS population into Large/Medium/Small', function () {
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id, 'name' => 'HQ', 'lat' => 40.80, 'lng' => -74.20, 'home_county_geoid' => '34013', 'county_geoids' => ['34013']]);

    $result = (new CountyCoverage(essexGazetteer(), essexPopulation()))->coverage($site);

    expect($result->unionCount())->toBe(3);

    $buckets = CoverageResult::bucketCounts($result->union);
    expect($buckets)->toBe(['large' => 1, 'medium' => 1, 'small' => 1, 'unknown' => 0]);

    $belleville = collect($result->union)->firstWhere('geoId', '3401305580');
    expect($belleville->population)->toBe(36000)
        ->and($belleville->bucket(config('launchpad.locations.population_buckets'))->value)->toBe('large');
});

test('it unions + GEOID-dedupes the same county across two locations (overlap)', function () {
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id, 'name' => 'A', 'lat' => 40.80, 'lng' => -74.20, 'home_county_geoid' => '34013', 'county_geoids' => ['34013']]);
    Location::factory()->create(['site_id' => $site->id, 'name' => 'B', 'lat' => 40.82, 'lng' => -74.25, 'home_county_geoid' => '34013', 'county_geoids' => ['34013']]);

    $result = (new CountyCoverage(essexGazetteer(), essexPopulation()))->coverage($site);

    // 3 distinct towns, each reached by BOTH bases
    expect($result->unionCount())->toBe(3)
        ->and($result->overlapCount())->toBe(3)
        ->and(collect($result->union)->every(fn ($m) => count($m->sourceLocationIds) === 2))->toBeTrue();
});

test('with no selected county a location contributes nothing', function () {
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id, 'name' => 'HQ', 'lat' => 40.80, 'lng' => -74.20, 'home_county_geoid' => '34013', 'county_geoids' => []]);

    $result = (new CountyCoverage(essexGazetteer(), essexPopulation()))->coverage($site);

    expect($result->perBase)->toBe([])
        ->and($result->unionCount())->toBe(0);
});

test('without a Census key towns come back ungrouped (population null), never erroring', function () {
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id, 'name' => 'HQ', 'lat' => 40.80, 'lng' => -74.20, 'home_county_geoid' => '34013', 'county_geoids' => ['34013']]);

    $noKey = new CensusPopulation(app(Http::class), app(Cache::class), '', '2022');
    $result = (new CountyCoverage(essexGazetteer(), $noKey))->coverage($site);

    expect($result->unionCount())->toBe(3);
    $buckets = CoverageResult::bucketCounts($result->union);
    expect($buckets['unknown'])->toBe(3);
});
