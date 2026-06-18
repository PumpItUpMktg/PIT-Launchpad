<?php

use App\Enums\MunicipalityType;
use App\Integrations\Census\MockMunicipalityGazetteer;
use App\Locations\CoverageWriter;
use App\Locations\LocationCoverage;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Tests\Support\CoverageFixture;

function writeCoverage(Site $site): int
{
    $result = (new LocationCoverage(new MockMunicipalityGazetteer(CoverageFixture::municipalities())))->coverage($site);

    return app(CoverageWriter::class)->write($site, $result);
}

function areasFor(Site $site)
{
    return CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id);
}

test('it persists the union as the site CoverageArea set', function () {
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id, 'lat' => CoverageFixture::A_LAT, 'lng' => CoverageFixture::A_LNG, 'coverage_radius' => 25]);

    $count = writeCoverage($site);

    expect($count)->toBe(3)
        ->and(areasFor($site)->count())->toBe(3);

    $livingston = areasFor($site)->where('name', 'Livingston Twp')->first();
    expect($livingston->type)->toBe(MunicipalityType::CountySubdivision)
        ->and($livingston->state)->toBe('NJ')
        ->and((float) $livingston->distance_miles)->toBeGreaterThan(0.0)
        ->and($livingston->source_location_ids)->toBeArray();
});

test('re-running replaces the prior coverage set (no duplication)', function () {
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id, 'lat' => CoverageFixture::A_LAT, 'lng' => CoverageFixture::A_LNG, 'coverage_radius' => 25]);

    writeCoverage($site);
    writeCoverage($site);

    expect(areasFor($site)->count())->toBe(3); // not doubled
});

test('it replaces stale radius-era rows (same + dropped GEOIDs) without a unique-key 500, manual survives', function () {
    $site = Site::factory()->create();
    Location::factory()->create(['site_id' => $site->id, 'lat' => CoverageFixture::A_LAT, 'lng' => CoverageFixture::A_LNG, 'coverage_radius' => 25]);

    // stale radius-era row sharing a GEOID the new county set will also produce (the collision)
    CoverageArea::create(['site_id' => $site->id, 'geo_id' => '3441310', 'name' => 'Old Livingston', 'type' => MunicipalityType::CountySubdivision, 'state' => 'NJ', 'source' => 'radius']);
    // a stale radius row NOT in the new set — must be dropped
    CoverageArea::create(['site_id' => $site->id, 'geo_id' => '9999999', 'name' => 'Gone', 'type' => MunicipalityType::Place, 'state' => 'NJ', 'source' => 'radius']);
    // an owner manual add — must survive the recompute
    CoverageArea::create(['site_id' => $site->id, 'geo_id' => '4299999', 'name' => 'East Newark', 'type' => MunicipalityType::CountySubdivision, 'state' => 'NJ', 'source' => 'manual']);

    $count = writeCoverage($site); // fixture union → Maplewood, Livingston (3441310), Clinton

    expect($count)->toBe(3)
        ->and(areasFor($site)->where('source', 'radius')->count())->toBe(0)        // stale radius rows gone
        ->and(areasFor($site)->where('geo_id', '3441310')->count())->toBe(1)        // shared GEOID written once (no 500)
        ->and(areasFor($site)->where('geo_id', '9999999')->exists())->toBeFalse()   // no-longer-covered row dropped
        ->and(areasFor($site)->where('source', 'manual')->where('geo_id', '4299999')->count())->toBe(1); // manual survives
});
