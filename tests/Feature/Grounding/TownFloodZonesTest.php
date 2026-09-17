<?php

use App\GeoGrid\TownOutlines;
use App\Integrations\Census\MockMunicipalityGazetteer;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Local\Grounding\TownFloodFacts;
use App\Local\Grounding\TownFloodSync;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Site;
use App\Models\TownFloodZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** The NFHL's grouped answer, shaped exactly as the live service returns it (verified against Warrington, PA). */
function nfhlResponse(array $rows): array
{
    return ['features' => array_map(fn (array $r): array => ['attributes' => [
        'FLD_ZONE' => $r[0], 'SFHA_TF' => $r[1], 'n' => $r[2],
    ]], $rows)];
}

function floodSite(): Site
{
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.com']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.3, 'lng' => -75.1]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Warrington', 'state' => 'PA',
        'geo_id' => '4201781048', 'population' => 25597, 'source_location_ids' => [$loc->id]]);

    return $site;
}

/** A square ring around the town — the shape the gazetteer would hand back. */
function ringFor(): array
{
    return [[
        ['lat' => 40.21, 'lng' => -75.20], ['lat' => 40.29, 'lng' => -75.20],
        ['lat' => 40.29, 'lng' => -75.10], ['lat' => 40.21, 'lng' => -75.10],
        ['lat' => 40.21, 'lng' => -75.20],
    ]];
}

/**
 * Seed the boundary cache the way a warmed gazetteer would. TownOutlines is final — an anonymous subclass
 * of it is a fatal, not a double — and it reads its cache before reaching for the gazetteer, so this is
 * both the supported seam and the faster one.
 */
function fakeOutlines(array $rings): void
{
    Cache::flush();
    foreach ($rings as $geoId => $townRings) {
        Cache::put('lp.town_outline.'.$geoId, $townRings, now()->addDay());
    }
}

it('asks FEMA with the town\'s own boundary and stores what it mapped', function () {
    Http::fake(['*/NFHL/MapServer/28/query' => Http::response(nfhlResponse([['AE', 'T', 43], ['X', 'F', 41], ['A', 'T', 6]]))]);
    fakeOutlines(['4201781048' => ringFor()]);
    $site = floodSite();

    $result = app(TownFloodSync::class)->forSite($site);

    expect($result)->toMatchArray(['towns' => 1, 'fetched' => 1, 'mapped' => 1, 'unmapped' => 0]);

    // The REAL boundary is sent as a polygon, never a bounding box around it.
    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return $body['geometryType'] === 'esriGeometryPolygon'
            && str_contains((string) $body['geometry'], '-75.2')
            && $body['groupByFieldsForStatistics'] === 'FLD_ZONE,SFHA_TF';
    });

    $row = TownFloodZone::query()->where('geo_id', '4201781048')->sole();
    expect($row->mapped)->toBeTrue()
        ->and($row->has_sfha)->toBeTrue()
        ->and($row->sfhaZones())->toBe(['A', 'AE'])   // X is not a Special Flood Hazard Area
        ->and($row->zones)->toHaveCount(3);

    // Held towns are skipped on the next pass — the run is resumable, not repeated.
    Http::fake();
    expect(app(TownFloodSync::class)->forSite($site))->toMatchArray(['fetched' => 0, 'outstanding' => 0]);
});

it('separates "FEMA mapped no hazard here" from "FEMA has not mapped here"', function () {
    Http::fake(['*/NFHL/MapServer/28/query' => Http::response(['features' => []])]);
    fakeOutlines(['4201781048' => ringFor()]);
    $site = floodSite();

    app(TownFloodSync::class)->forSite($site);

    $row = TownFloodZone::query()->sole();
    expect($row->mapped)->toBeFalse()->and($row->has_sfha)->toBeFalse();
    // Unmapped says nothing at all — silence, not a clean bill of health.
    expect(app(TownFloodFacts::class)->for($row))->toBe([]);

    $clean = new TownFloodZone(['name' => 'Warrington', 'mapped' => true, 'has_sfha' => false, 'zones' => [['zone' => 'X', 'sfha' => false, 'polygons' => 41]]]);
    expect(app(TownFloodFacts::class)->for($clean))
        ->toBe(['FEMA maps no Special Flood Hazard Area within Warrington — it lies outside the 1%-annual-chance floodplain.']);
});

it('names the zones and refuses to speak about any one address', function () {
    $flood = new TownFloodZone(['name' => 'Warrington', 'mapped' => true, 'has_sfha' => true, 'zones' => [
        ['zone' => 'AE', 'sfha' => true, 'polygons' => 43],
        ['zone' => 'X', 'sfha' => false, 'polygons' => 41],
        ['zone' => 'A', 'sfha' => true, 'polygons' => 6],
    ]]);

    $facts = app(TownFloodFacts::class)->for($flood);

    expect($facts[0])->toBe('FEMA maps Special Flood Hazard Areas in parts of Warrington (zones A and AE) — the regulatory floodplain.')
        ->and($facts[1])->toBe('Zone AE is the 1%-annual-chance floodplain, with base flood elevations published.')
        ->and($facts[2])->toBe('Whether any one address in Warrington sits inside that zone depends on the address, not the town.');

    // Never a share of the town: intersecting polygons are not clipped to it, so no percentage exists.
    expect(implode(' ', $facts))->not->toContain('%%')->not->toContain('of the town');
    // And never a claim about the reader's own property.
    expect(implode(' ', $facts))->not->toContain('your home')->not->toContain('your house');
});

it('leaves a town with no Census boundary alone rather than recording a FEMA answer it never got', function () {
    Http::fake();
    fakeOutlines([]);   // nothing cached, and the mock gazetteer knows no fixture for this GEOID
    app()->bind(MunicipalityGazetteer::class, MockMunicipalityGazetteer::class);
    $site = floodSite();

    $result = app(TownFloodSync::class)->forSite($site);

    expect($result['no_boundary'])->toBe([['name' => 'Warrington', 'geo_id' => '4201781048']])
        ->and($result['fetched'])->toBe(0)
        ->and(TownFloodZone::query()->count())->toBe(0);
    Http::assertNothingSent();
});
