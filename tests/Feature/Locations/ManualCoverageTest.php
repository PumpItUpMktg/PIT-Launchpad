<?php

use App\Enums\MunicipalityType;
use App\Integrations\Census\MockMunicipalityGazetteer;
use App\Integrations\Census\Municipality;
use App\Locations\CoverageWriter;
use App\Locations\LocationCoverage;
use App\Locations\ManualCoverage;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Tests\Support\CoverageFixture;

function manualCoverage(): ManualCoverage
{
    return new ManualCoverage(new MockMunicipalityGazetteer(CoverageFixture::municipalities()));
}

function farawayTown(): Municipality
{
    // a place well outside base A's 25mi radius — net-new directed coverage
    return new Municipality('4299999', 'Faraway', MunicipalityType::Place, 'PA', 41.20, -76.30);
}

test('search finds municipalities by name', function () {
    expect(manualCoverage()->search('clinton'))->toHaveCount(1)
        ->and(collect(manualCoverage()->search('twp'))->pluck('name'))->toContain('Livingston Twp', 'Clinton Twp');
});

test('add creates a manual coverage area and remove deletes it', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id]);

    manualCoverage()->add($site, $loc, farawayTown());
    $row = CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('source', 'manual')->first();
    expect($row)->not->toBeNull()
        ->and($row->geo_id)->toBe('4299999')
        ->and($row->source_location_ids)->toBe([$loc->id]);

    manualCoverage()->remove($site, '4299999');
    expect(CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('source', 'manual')->count())->toBe(0);
});

test('a manual add is net-new in the union, flagged manual, and outside the radius', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => CoverageFixture::A_LAT, 'lng' => CoverageFixture::A_LNG, 'coverage_radius' => 25]);
    manualCoverage()->add($site, $loc, farawayTown());

    $result = (new LocationCoverage(new MockMunicipalityGazetteer(CoverageFixture::municipalities())))->coverage($site);
    $faraway = collect($result->union)->firstWhere('geoId', '4299999');

    expect($result->unionCount())->toBe(4) // 3 radius + 1 manual net-new
        ->and($faraway)->not->toBeNull()
        ->and($faraway->manual)->toBeTrue();
});

test('manual coverage survives a radius recompute (CoverageWriter rebuilds only radius rows)', function () {
    $site = Site::factory()->create();
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => CoverageFixture::A_LAT, 'lng' => CoverageFixture::A_LNG, 'coverage_radius' => 25]);
    manualCoverage()->add($site, $loc, farawayTown());

    $engine = new LocationCoverage(new MockMunicipalityGazetteer(CoverageFixture::municipalities()));
    $writer = new CoverageWriter;
    $writer->write($site, $engine->coverage($site)); // recompute #1
    $writer->write($site, $engine->coverage($site)); // recompute #2 — must not wipe the manual add

    expect(CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('source', 'manual')->where('geo_id', '4299999')->count())->toBe(1)
        ->and(CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('source', 'radius')->count())->toBe(3);
});
