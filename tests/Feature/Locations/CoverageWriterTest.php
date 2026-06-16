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
