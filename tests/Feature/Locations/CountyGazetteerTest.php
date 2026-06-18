<?php

use App\Enums\MunicipalityType;
use App\Integrations\Census\TigerwebGazetteer;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Http as HttpFacade;

const TW = 'https://tigerweb.example/MapServer';

function countyLayerDefs()
{
    return HttpFacade::response(['layers' => [
        ['id' => 28, 'name' => 'Incorporated Places'],
        ['id' => 22, 'name' => 'County Subdivisions'],
        ['id' => 82, 'name' => 'Counties'],
    ]]);
}

function gaz(): TigerwebGazetteer
{
    return new TigerwebGazetteer(app(Http::class), TW, 28, 22, 30, 82);
}

test('countyAt resolves the home county from a point (layer 82, no STUSAB)', function () {
    HttpFacade::fake([
        TW.'?f=json' => countyLayerDefs(),
        '*/82/query*' => HttpFacade::response(['features' => [
            ['attributes' => ['GEOID' => '34013', 'NAME' => 'Essex County', 'STATE' => '34', 'COUNTY' => '013']],
        ]]),
    ]);

    $county = gaz()->countyAt(40.814, -74.22);

    expect($county->geoId)->toBe('34013')
        ->and($county->name)->toBe('Essex County')
        ->and($county->stateFips)->toBe('34')
        ->and($county->countyFips)->toBe('013');

    HttpFacade::assertSent(fn ($r) => str_contains($r->url(), '/82/query')
        && $r['geometryType'] === 'esriGeometryPoint'
        && (string) $r['inSR'] === '4326'
        && ! str_contains((string) $r['outFields'], 'STUSAB'));
});

test('countiesInState lists a state’s counties via WHERE STATE', function () {
    HttpFacade::fake([
        TW.'?f=json' => countyLayerDefs(),
        '*/82/query*' => HttpFacade::response(['features' => [
            ['attributes' => ['GEOID' => '34003', 'NAME' => 'Bergen County', 'STATE' => '34', 'COUNTY' => '003']],
            ['attributes' => ['GEOID' => '34013', 'NAME' => 'Essex County', 'STATE' => '34', 'COUNTY' => '013']],
        ]]),
    ]);

    $counties = gaz()->countiesInState('34');

    expect($counties)->toHaveCount(2)
        ->and(collect($counties)->pluck('name'))->toContain('Bergen County', 'Essex County');
    HttpFacade::assertSent(fn ($r) => str_contains($r->url(), '/82/query') && str_contains(urldecode($r->url()), "STATE='34'"));
});

test('subdivisionsInCounty enumerates a county’s municipalities via WHERE STATE AND COUNTY', function () {
    HttpFacade::fake([
        TW.'?f=json' => countyLayerDefs(),
        '*/22/query*' => HttpFacade::response(['features' => [
            ['attributes' => ['GEOID' => '3401305580', 'NAME' => 'Belleville township', 'BASENAME' => 'Belleville', 'STATE' => '34', 'CENTLAT' => '40.79', 'CENTLON' => '-74.15']],
            ['attributes' => ['GEOID' => '3401351210', 'NAME' => 'Montclair township', 'BASENAME' => 'Montclair', 'STATE' => '34', 'CENTLAT' => '40.82', 'CENTLON' => '-74.21']],
        ]]),
    ]);

    $subs = gaz()->subdivisionsInCounty('34', '013');

    expect($subs)->toHaveCount(2)
        ->and($subs[0]->type)->toBe(MunicipalityType::CountySubdivision)
        ->and($subs[0]->name)->toBe('Belleville')        // BASENAME preferred
        ->and($subs[0]->state)->toBe('NJ');               // STATE FIPS 34 → NJ
    HttpFacade::assertSent(fn ($r) => str_contains($r->url(), '/22/query')
        && str_contains(urldecode($r->url()), "STATE='34' AND COUNTY='013'")
        && ! str_contains((string) $r['outFields'], 'STUSAB'));
});
