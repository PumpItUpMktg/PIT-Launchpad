<?php

use App\Integrations\Census\MockMunicipalityGazetteer;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Tests\Support\CoverageFixture;

function fakeGazetteer(): void
{
    app()->instance(MunicipalityGazetteer::class, new MockMunicipalityGazetteer(CoverageFixture::municipalities()));
}

function siteWithBase(): Site
{
    $site = Site::factory()->create();
    Location::factory()->create([
        'site_id' => $site->id,
        'name' => 'HQ',
        'lat' => CoverageFixture::A_LAT,
        'lng' => CoverageFixture::A_LNG,
        'coverage_radius' => 25,
    ]);

    return $site;
}

test('dry-run prints the coverage set and writes nothing', function () {
    fakeGazetteer();
    $site = siteWithBase();

    $this->artisan('launchpad:locations-coverage', ['site' => $site->id])
        ->expectsOutputToContain('Maplewood')
        ->expectsOutputToContain('UNION')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);
});

test('--persist writes the coverage set', function () {
    fakeGazetteer();
    $site = siteWithBase();

    $this->artisan('launchpad:locations-coverage', ['site' => $site->id, '--persist' => true])
        ->expectsOutputToContain('Persisted coverage set')
        ->assertSuccessful();

    expect(CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(3);
});

test('--json emits the raw set', function () {
    fakeGazetteer();
    $site = siteWithBase();

    $this->artisan('launchpad:locations-coverage', ['site' => $site->id, '--json' => true])
        ->expectsOutputToContain('"union"')
        ->assertSuccessful();
});

test('it fails on an unknown site', function () {
    fakeGazetteer();
    $this->artisan('launchpad:locations-coverage', ['site' => 'missing'])->assertFailed();
});

test('--radius applies to all bases for the run without persisting the radius', function () {
    fakeGazetteer();
    $site = Site::factory()->create();
    $loc = Location::factory()->create([
        'site_id' => $site->id, 'name' => 'HQ',
        'lat' => CoverageFixture::A_LAT, 'lng' => CoverageFixture::A_LNG,
        'coverage_radius' => null, // no saved radius
    ]);

    $this->artisan('launchpad:locations-coverage', ['site' => $site->id, '--radius' => 25])
        ->expectsOutputToContain('Maplewood')
        ->assertSuccessful();

    // run-only: the Location radius is untouched
    expect(Location::withoutGlobalScope(SiteScope::class)->where('id', $loc->id)->value('coverage_radius'))->toBeNull();
});

test('--radius --save persists the radius onto the base locations', function () {
    fakeGazetteer();
    $site = Site::factory()->create();
    $loc = Location::factory()->create([
        'site_id' => $site->id, 'name' => 'HQ',
        'lat' => CoverageFixture::A_LAT, 'lng' => CoverageFixture::A_LNG,
        'coverage_radius' => null,
    ]);

    $this->artisan('launchpad:locations-coverage', ['site' => $site->id, '--radius' => 15, '--save' => true])
        ->expectsOutputToContain('Saved radius 15mi')
        ->assertSuccessful();

    expect(Location::withoutGlobalScope(SiteScope::class)->where('id', $loc->id)->value('coverage_radius'))->toBe(15);
});

test('--save without --radius fails', function () {
    fakeGazetteer();
    $site = siteWithBase();

    $this->artisan('launchpad:locations-coverage', ['site' => $site->id, '--save' => true])
        ->expectsOutputToContain('--save requires --radius')
        ->assertFailed();
});

test('it fails when no base location is configured', function () {
    fakeGazetteer();
    $site = Site::factory()->create(); // no locations

    $this->artisan('launchpad:locations-coverage', ['site' => $site->id])
        ->expectsOutputToContain('No configured base locations')
        ->assertFailed();
});
