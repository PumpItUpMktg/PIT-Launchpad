<?php

use App\Enums\MunicipalityType;
use App\Integrations\Census\County;
use App\Integrations\Census\MockMunicipalityGazetteer;
use App\Integrations\Census\Municipality;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

/**
 * Wire a county gazetteer: Essex County, NJ (34013) with three subdivisions. The page /
 * command read coverage from a location's selected counties, not a radius.
 */
function fakeCountyGazetteer(): void
{
    app()->instance(MunicipalityGazetteer::class, new MockMunicipalityGazetteer(
        municipalities: [],
        counties: [new County('34013', 'Essex', '34', '013')],
        subdivisions: [
            '34:013' => [
                new Municipality('3401305580', 'Belleville Twp', MunicipalityType::CountySubdivision, 'NJ', 40.79, -74.15),
                new Municipality('3401351210', 'Montclair Twp', MunicipalityType::CountySubdivision, 'NJ', 40.82, -74.21),
                new Municipality('3401321840', 'Essex Fells Boro', MunicipalityType::CountySubdivision, 'NJ', 40.82, -74.28),
            ],
        ],
    ));
}

function siteWithCountyBase(): Site
{
    $site = Site::factory()->create();
    Location::factory()->create([
        'site_id' => $site->id,
        'name' => 'HQ',
        'lat' => 40.80,
        'lng' => -74.20,
        'home_county_geoid' => '34013',
        'county_geoids' => ['34013'],
    ]);

    return $site;
}

test('dry-run prints the coverage set and writes nothing', function () {
    fakeCountyGazetteer();
    $site = siteWithCountyBase();

    $this->artisan('launchpad:locations-coverage', ['site' => $site->id])
        ->expectsOutputToContain('Montclair Twp')
        ->expectsOutputToContain('UNION')
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(0);
});

test('--persist writes the coverage set', function () {
    fakeCountyGazetteer();
    $site = siteWithCountyBase();

    $this->artisan('launchpad:locations-coverage', ['site' => $site->id, '--persist' => true])
        ->expectsOutputToContain('Persisted coverage set')
        ->assertSuccessful();

    expect(CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('source', 'county')->count())->toBe(3);
});

test('--json emits the raw set', function () {
    fakeCountyGazetteer();
    $site = siteWithCountyBase();

    $this->artisan('launchpad:locations-coverage', ['site' => $site->id, '--json' => true])
        ->expectsOutputToContain('"union"')
        ->assertSuccessful();
});

test('it fails on an unknown site', function () {
    fakeCountyGazetteer();
    $this->artisan('launchpad:locations-coverage', ['site' => 'missing'])->assertFailed();
});

test('it fails when no base location has a selected county', function () {
    fakeCountyGazetteer();
    $site = Site::factory()->create(); // no locations / no counties

    $this->artisan('launchpad:locations-coverage', ['site' => $site->id])
        ->expectsOutputToContain('No coverage')
        ->assertFailed();
});
