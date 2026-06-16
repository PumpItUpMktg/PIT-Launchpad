<?php

use App\Enums\MunicipalityType;
use App\Integrations\Census\TigerwebGazetteer;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Http as HttpFacade;

test('it queries both the places and county-subdivision layers and normalizes features', function () {
    HttpFacade::fake([
        '*/28/query*' => HttpFacade::response(['features' => [
            ['attributes' => ['GEOID' => '3445000', 'NAME' => 'Maplewood', 'BASENAME' => 'Maplewood', 'STUSAB' => 'NJ', 'CENTLAT' => '40.7312', 'CENTLON' => '-74.4710']],
        ]]),
        '*/22/query*' => HttpFacade::response(['features' => [
            ['attributes' => ['GEOID' => '3401990', 'NAME' => 'Clinton township', 'BASENAME' => 'Clinton', 'STUSAB' => 'NJ', 'CENTLAT' => '40.61', 'CENTLON' => '-74.90']],
            ['attributes' => ['GEOID' => '', 'NAME' => 'junk']], // no GEOID → skipped
        ]]),
    ]);

    $gazetteer = new TigerwebGazetteer(app(Http::class), 'https://tigerweb.example/MapServer', 28, 22, 30);
    $found = $gazetteer->near(40.70, -74.50, 25);

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

test('it sends a statute-mile point-buffer spatial query', function () {
    HttpFacade::fake(['*' => HttpFacade::response(['features' => []])]);

    (new TigerwebGazetteer(app(Http::class), 'https://tigerweb.example/MapServer', 28, 22, 30))
        ->near(40.70, -74.50, 15);

    HttpFacade::assertSent(fn ($request) => str_contains($request->url(), '/28/query')
        && $request['units'] === 'esriSRUnit_StatuteMile'
        && (string) $request['distance'] === '15'
        && $request['geometryType'] === 'esriGeometryPoint');
});
