<?php

use App\Enums\MunicipalityType;
use App\Integrations\Census\MockMunicipalityGazetteer;
use App\Locations\CoverageResult;
use App\Locations\LocationCoverage;
use App\Models\Location;
use App\Models\Site;
use Tests\Support\CoverageFixture;

function coverageFor(Site $site): CoverageResult
{
    return (new LocationCoverage(new MockMunicipalityGazetteer(CoverageFixture::municipalities())))->coverage($site);
}

function baseLocation(Site $site, float $lat, float $lng, ?int $radius = 25): Location
{
    return Location::factory()->create([
        'site_id' => $site->id,
        'lat' => $lat,
        'lng' => $lng,
        'coverage_radius' => $radius,
    ]);
}

test('it distance-filters the enumeration to the radius (drops far + null-centroid)', function () {
    $site = Site::factory()->create();
    baseLocation($site, CoverageFixture::A_LAT, CoverageFixture::A_LNG);

    $names = collect(coverageFor($site)->union)->pluck('name');

    expect($names)->toContain('Maplewood')
        ->and($names)->toContain('Livingston Twp')
        ->and($names)->not->toContain('Scranton')      // out of radius
        ->and($names)->not->toContain('No Centroid');  // unusable centroid
});

test('it includes county subdivisions (MCDs), not just incorporated places', function () {
    $site = Site::factory()->create();
    baseLocation($site, CoverageFixture::A_LAT, CoverageFixture::A_LNG);

    $result = coverageFor($site);
    $livingston = collect($result->union)->firstWhere('name', 'Livingston Twp');

    expect($livingston->type)->toBe(MunicipalityType::CountySubdivision)
        ->and($result->mcdCount())->toBeGreaterThan(0);
});

test('the radius crosses state lines (a PA town from a near-border base)', function () {
    $site = Site::factory()->create();
    baseLocation($site, CoverageFixture::B_LAT, CoverageFixture::B_LNG);

    $easton = collect(coverageFor($site)->union)->firstWhere('name', 'Easton');

    expect($easton)->not->toBeNull()
        ->and($easton->state)->toBe('PA');
});

test('multi-location union dedupes a shared municipality and records both sources', function () {
    $site = Site::factory()->create();
    $a = baseLocation($site, CoverageFixture::A_LAT, CoverageFixture::A_LNG);
    $b = baseLocation($site, CoverageFixture::B_LAT, CoverageFixture::B_LNG);

    $result = coverageFor($site);

    // A: Maplewood, Livingston, Clinton  | B: Easton, Clinton  → union of 4 (Clinton shared)
    expect($result->unionCount())->toBe(4)
        ->and($result->placeCount())->toBe(2)
        ->and($result->mcdCount())->toBe(2)
        ->and($result->perBase)->toHaveCount(2);

    $clinton = collect($result->union)->firstWhere('name', 'Clinton Twp');
    expect($clinton->sourceLocationIds)->toHaveCount(2)
        ->and($clinton->sourceLocationIds)->toContain($a->id)
        ->and($clinton->sourceLocationIds)->toContain($b->id);
});

test('it skips base locations with no point or no radius', function () {
    $site = Site::factory()->create();
    baseLocation($site, CoverageFixture::A_LAT, CoverageFixture::A_LNG, radius: null); // no radius
    Location::factory()->create(['site_id' => $site->id, 'lat' => null, 'lng' => null, 'coverage_radius' => 25]); // no point

    expect(coverageFor($site)->perBase)->toBe([]);
});
