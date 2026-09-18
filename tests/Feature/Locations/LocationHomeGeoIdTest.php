<?php

use App\Enums\MunicipalityType;
use App\Integrations\Census\County;
use App\Integrations\Census\Geocoder;
use App\Integrations\Census\Municipality;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Jobs\GeocodeLocation;
use App\Models\Location;
use App\Models\Site;

/**
 * A location's own coordinates settle which municipality it stands in. That matters because the name
 * cannot: an office in Doylestown is in the borough OR the township, one county, two GEOIDs. The point
 * intersects exactly one of them, and the gazetteer asks the MCD layer first for precisely that case.
 */
it('records the municipality the geocoded point falls in, alongside the county', function () {
    $gazetteer = Mockery::mock(MunicipalityGazetteer::class);
    $gazetteer->shouldReceive('countyAt')->andReturn(new County('42017', 'Bucks County', '42', '017'));
    $gazetteer->shouldReceive('placeAt')->with(40.3101, -75.1299)->andReturn(
        new Municipality('4201720328', 'Doylestown', MunicipalityType::CountySubdivision, 'PA'),
    );
    app()->instance(MunicipalityGazetteer::class, $gazetteer);

    $site = Site::factory()->create();
    $location = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.3101, 'lng' => -75.1299]);

    app(GeocodeLocation::class, ['locationId' => (string) $location->id])
        ->handle(app(Geocoder::class), $gazetteer);

    expect($location->fresh()->home_geo_id)->toBe('4201720328')
        ->and($location->fresh()->home_county_geoid)->toBe('42017');
});

/** A point that falls in no census municipality leaves the field null — never a guess from the name. */
it('leaves the municipality null when the point resolves to none', function () {
    $gazetteer = Mockery::mock(MunicipalityGazetteer::class);
    $gazetteer->shouldReceive('countyAt')->andReturn(null);
    $gazetteer->shouldReceive('placeAt')->andReturn(null);
    app()->instance(MunicipalityGazetteer::class, $gazetteer);

    $site = Site::factory()->create();
    $location = Location::factory()->create(['site_id' => $site->id, 'lat' => 39.0, 'lng' => -74.0]);

    app(GeocodeLocation::class, ['locationId' => (string) $location->id])
        ->handle(app(Geocoder::class), $gazetteer);

    expect($location->fresh()->home_geo_id)->toBeNull();
});
