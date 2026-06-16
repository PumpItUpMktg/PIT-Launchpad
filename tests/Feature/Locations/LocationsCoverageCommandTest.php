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

test('it fails when no base location is configured', function () {
    fakeGazetteer();
    $site = Site::factory()->create(); // no locations

    $this->artisan('launchpad:locations-coverage', ['site' => $site->id])
        ->expectsOutputToContain('No configured base locations')
        ->assertFailed();
});
