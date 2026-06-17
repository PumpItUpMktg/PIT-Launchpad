<?php

use App\Enums\MunicipalityType;
use App\Integrations\Census\TigerwebDebug;
use App\Integrations\Census\TigerwebGazetteer;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Http as HttpFacade;
use Illuminate\Support\Facades\Log;

const TIGERWEB = 'https://tigerweb.example/MapServer';

/** The MapServer layer-definition response (resolves ids by name). */
function layerDefs(int $places, int $cousub)
{
    return HttpFacade::response(['layers' => [
        ['id' => $places, 'name' => 'Incorporated Places'],
        ['id' => $cousub, 'name' => 'County Subdivisions'],
        ['id' => 99, 'name' => 'States'],
    ]]);
}

test('it resolves layer ids by name then queries both layers and normalizes features', function () {
    HttpFacade::fake([
        TIGERWEB.'?f=json' => layerDefs(28, 22),
        '*/28/query*' => HttpFacade::response(['features' => [
            ['attributes' => ['GEOID' => '3445000', 'NAME' => 'Maplewood', 'BASENAME' => 'Maplewood', 'STUSAB' => 'NJ', 'CENTLAT' => '40.7312', 'CENTLON' => '-74.4710']],
        ]]),
        '*/22/query*' => HttpFacade::response(['features' => [
            ['attributes' => ['GEOID' => '3401990', 'NAME' => 'Clinton township', 'BASENAME' => 'Clinton', 'STUSAB' => 'NJ', 'CENTLAT' => '40.61', 'CENTLON' => '-74.90']],
            ['attributes' => ['GEOID' => '', 'NAME' => 'junk']], // no GEOID → skipped
        ]]),
    ]);

    $found = (new TigerwebGazetteer(app(Http::class), TIGERWEB, 28, 22, 30))->near(40.70, -74.50, 25);

    expect($found)->toHaveCount(2);

    $place = collect($found)->firstWhere('geoId', '3445000');
    expect($place->type)->toBe(MunicipalityType::Place)
        ->and($place->name)->toBe('Maplewood')
        ->and($place->state)->toBe('NJ')
        ->and($place->lat)->toBe(40.7312);

    $mcd = collect($found)->firstWhere('geoId', '3401990');
    expect($mcd->type)->toBe(MunicipalityType::CountySubdivision)
        ->and($mcd->name)->toBe('Clinton'); // BASENAME preferred
});

test('name resolution beats the hardcoded fallback when a vintage moves the ids', function () {
    // The dedicated Places_CouSub service numbers Places = 4, County Subdivisions = 1.
    HttpFacade::fake([
        TIGERWEB.'?f=json' => layerDefs(4, 1),
        '*' => HttpFacade::response(['features' => []]),
    ]);

    // Fallback ids are the WRONG (Current) 28/22 — name resolution must override them.
    (new TigerwebGazetteer(app(Http::class), TIGERWEB, 28, 22, 30))->near(40.70, -74.50, 15);

    HttpFacade::assertSent(fn ($r) => str_contains($r->url(), '/4/query'));
    HttpFacade::assertSent(fn ($r) => str_contains($r->url(), '/1/query'));
    HttpFacade::assertNotSent(fn ($r) => str_contains($r->url(), '/28/query'));
});

test('it falls back to the configured ids when the layer lookup fails', function () {
    HttpFacade::fake([
        TIGERWEB.'?f=json' => HttpFacade::response('', 500),
        '*' => HttpFacade::response(['features' => []]),
    ]);

    (new TigerwebGazetteer(app(Http::class), TIGERWEB, 28, 22, 30))->near(40.70, -74.50, 15);

    HttpFacade::assertSent(fn ($r) => str_contains($r->url(), '/28/query'));
    HttpFacade::assertSent(fn ($r) => str_contains($r->url(), '/22/query'));
});

test('it sends a statute-mile point-buffer spatial query in 4326, geodesic (the zero-features fix)', function () {
    HttpFacade::fake([
        TIGERWEB.'?f=json' => layerDefs(28, 22),
        '*' => HttpFacade::response(['features' => []]),
    ]);

    (new TigerwebGazetteer(app(Http::class), TIGERWEB, 28, 22, 30))->near(40.70, -74.50, 15);

    HttpFacade::assertSent(fn ($request) => str_contains($request->url(), '/28/query')
        && $request['units'] === 'esriSRUnit_StatuteMile'
        && (string) $request['distance'] === '15'
        && $request['geometryType'] === 'esriGeometryPoint'
        && (string) $request['inSR'] === '4326'   // point is lat/lng, not Web Mercator meters
        && $request['geodesic'] === 'true');       // buffer degrees by miles correctly
});

test('it records each query (URL + status + count) to TigerwebDebug for on-page surfacing', function () {
    HttpFacade::fake([
        TIGERWEB.'?f=json' => layerDefs(28, 22),
        '*' => HttpFacade::response(['features' => []]),
    ]);

    (new TigerwebGazetteer(app(Http::class), TIGERWEB, 28, 22, 30))->near(40.70, -74.50, 25);

    $debug = app(TigerwebDebug::class);
    expect($debug->queries)->toHaveCount(2) // places + cousub
        ->and($debug->lastUrl())->toContain('/query?')
        ->and($debug->summary())->toContain('0 features');
});

test('a zero-feature response is logged loudly (never a silent 0)', function () {
    HttpFacade::fake([
        TIGERWEB.'?f=json' => layerDefs(28, 22),
        '*' => HttpFacade::response(['features' => []]),
    ]);
    Log::spy();

    (new TigerwebGazetteer(app(Http::class), TIGERWEB, 28, 22, 30))->near(40.70, -74.50, 15);

    Log::shouldHaveReceived('warning')->withArgs(fn ($msg) => str_contains($msg, '0 features'))->atLeast()->once();
});
