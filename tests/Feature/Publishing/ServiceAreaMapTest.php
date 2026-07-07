<?php

use App\Integrations\Census\MunicipalityGazetteer;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Site;
use App\Publishing\Blocks\ServiceAreaMap;

function fakeGazetteerPolygons(array $byGeoId): void
{
    $gaz = Mockery::mock(MunicipalityGazetteer::class);
    $gaz->shouldReceive('countyPolygons')->andReturnUsing(function (array $geoIds) use ($byGeoId): array {
        $out = [];
        foreach ($geoIds as $g) {
            if (isset($byGeoId[(string) $g])) {
                $out[] = ['geo_id' => (string) $g, 'name' => $byGeoId[(string) $g]['name'], 'rings' => $byGeoId[(string) $g]['rings']];
            }
        }

        return $out;
    });
    app()->instance(MunicipalityGazetteer::class, $gaz);
}

it('resolves county polygons + tiered town points, largest-first, with a center', function () {
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id, 'county_geoids' => ['34013', '34017']]);

    fakeGazetteerPolygons([
        '34013' => ['name' => 'Essex County', 'rings' => [[['lat' => 40.8, 'lng' => -74.2], ['lat' => 40.7, 'lng' => -74.3]]]],
        '34017' => ['name' => 'Hudson County', 'rings' => [[['lat' => 40.7, 'lng' => -74.05], ['lat' => 40.72, 'lng' => -74.06]]]],
    ]);

    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Newark', 'size_tier' => 'major', 'population' => 300000, 'lat' => 40.73, 'lng' => -74.17]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Bloomfield', 'size_tier' => 'medium', 'population' => 50000, 'lat' => 40.80, 'lng' => -74.18]);

    $map = app(ServiceAreaMap::class)->for($site->id);

    expect($map)->not->toBeNull()
        ->and($map['counties'])->toHaveCount(2)
        ->and(collect($map['counties'])->pluck('name')->all())->toContain('Essex County', 'Hudson County')
        ->and($map['cities'][0]['name'])->toBe('Newark')          // major first
        ->and($map['cities'][0]['tier'])->toBe('major')
        ->and($map['cities'][1]['name'])->toBe('Bloomfield')      // then medium
        ->and($map['center'])->toHaveKeys(['lat', 'lng']);
});

it('drops towns without coordinates (a marker needs a point) but still maps the counties', function () {
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id, 'county_geoids' => ['34013']]);
    fakeGazetteerPolygons(['34013' => ['name' => 'Essex County', 'rings' => [[['lat' => 40.8, 'lng' => -74.2]]]]]);

    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Located Town', 'size_tier' => 'large', 'lat' => 40.7, 'lng' => -74.1]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Ungeocoded Town', 'size_tier' => 'small', 'lat' => null, 'lng' => null]);

    $map = app(ServiceAreaMap::class)->for($site->id);

    expect(collect($map['cities'])->pluck('name')->all())
        ->toContain('Located Town')
        ->not->toContain('Ungeocoded Town');
});

it('returns null when there is no geometry at all (no map to draw)', function () {
    $site = Site::factory()->create();

    expect(app(ServiceAreaMap::class)->for($site->id))->toBeNull();
});

it('survives a gazetteer failure — no polygons, but geocoded towns still map', function () {
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id, 'county_geoids' => ['34013']]);

    $gaz = Mockery::mock(MunicipalityGazetteer::class);
    $gaz->shouldReceive('countyPolygons')->andThrow(new RuntimeException('tigerweb down'));
    app()->instance(MunicipalityGazetteer::class, $gaz);

    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Newark', 'size_tier' => 'major', 'lat' => 40.73, 'lng' => -74.17]);

    $map = app(ServiceAreaMap::class)->for($site->id);

    expect($map)->not->toBeNull()
        ->and($map['counties'])->toBe([])
        ->and($map['cities'][0]['name'])->toBe('Newark');
});
