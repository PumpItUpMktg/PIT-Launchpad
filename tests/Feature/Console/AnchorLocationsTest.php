<?php

use App\Enums\MunicipalityType;
use App\Integrations\Census\Municipality;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Models\Location;
use App\Models\Site;

function anchorLocSite(array $overrides = []): array
{
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    $location = Location::factory()->create(array_merge([
        'site_id' => $site->id, 'name' => 'Doylestown', 'lat' => 40.3101, 'lng' => -75.1299,
        'home_geo_id' => null,
    ], $overrides));

    return ['site' => $site, 'location' => $location];
}

function fakeGazetteer(?Municipality $municipality): void
{
    $gazetteer = Mockery::mock(MunicipalityGazetteer::class);
    $gazetteer->shouldReceive('placeAt')->andReturn($municipality);
    app()->instance(MunicipalityGazetteer::class, $gazetteer);
}

it('reports the resolved municipality and writes nothing by default', function () {
    $f = anchorLocSite();
    fakeGazetteer(new Municipality('4201720328', 'Doylestown', MunicipalityType::CountySubdivision, 'PA'));

    $this->artisan('launchpad:anchor-locations', ['--site' => 'SPG'])
        ->expectsOutputToContain('Read-only')
        ->expectsOutputToContain('4201720328')
        ->assertSuccessful();

    expect($f['location']->fresh()->home_geo_id)->toBeNull();
});

it('writes the municipality under --execute', function () {
    $f = anchorLocSite();
    fakeGazetteer(new Municipality('4201720328', 'Doylestown', MunicipalityType::CountySubdivision, 'PA'));

    $this->artisan('launchpad:anchor-locations', ['--site' => 'SPG', '--execute' => true])
        ->expectsOutputToContain('EXECUTE')
        ->assertSuccessful();

    expect($f['location']->fresh()->home_geo_id)->toBe('4201720328');
});

/** No coordinates, no answer — the name is never used to guess which municipality an office is in. */
it('reports a location with no coordinates instead of guessing', function () {
    $f = anchorLocSite(['lat' => null, 'lng' => null]);
    fakeGazetteer(new Municipality('4201720328', 'Doylestown', MunicipalityType::CountySubdivision, 'PA'));

    $this->artisan('launchpad:anchor-locations', ['--site' => 'SPG', '--execute' => true])
        ->expectsOutputToContain('no coordinates')
        ->assertSuccessful();

    expect($f['location']->fresh()->home_geo_id)->toBeNull();
});

/** An already-anchored location is left alone unless --force asks for it again. */
it('skips an anchored location without --force', function () {
    $f = anchorLocSite(['home_geo_id' => '4201720344']);
    fakeGazetteer(new Municipality('4201720328', 'Doylestown', MunicipalityType::CountySubdivision, 'PA'));

    $this->artisan('launchpad:anchor-locations', ['--site' => 'SPG', '--execute' => true])
        ->expectsOutputToContain('already anchored')
        ->assertSuccessful();

    expect($f['location']->fresh()->home_geo_id)->toBe('4201720344');

    $this->artisan('launchpad:anchor-locations', ['--site' => 'SPG', '--execute' => true, '--force' => true])
        ->assertSuccessful();

    expect($f['location']->fresh()->home_geo_id)->toBe('4201720328');
});

/**
 * The office's mailing town is often not the municipality it stands in — SPG's "Doylestown" office is
 * in Plumstead township, "Trooper" in Lower Providence. Both facts are true and they are used for
 * different things, so the report says when they part company rather than letting one pass for the other.
 */
it('names the difference when the mailing town is not the municipality', function () {
    anchorLocSite([
        'address_components' => [
            ['types' => ['locality'], 'long_name' => 'Doylestown', 'short_name' => 'Doylestown'],
            ['types' => ['administrative_area_level_1'], 'long_name' => 'Pennsylvania', 'short_name' => 'PA'],
        ],
    ]);
    fakeGazetteer(new Municipality('4201761616', 'Plumstead', MunicipalityType::CountySubdivision, 'PA'));

    $this->artisan('launchpad:anchor-locations', ['--site' => 'SPG'])
        ->expectsOutputToContain('the building stands in Plumstead')
        ->assertSuccessful();
});

it('says nothing when the mailing town and the municipality agree', function () {
    anchorLocSite([
        'address_components' => [
            ['types' => ['locality'], 'long_name' => 'Hoboken', 'short_name' => 'Hoboken'],
            ['types' => ['administrative_area_level_1'], 'long_name' => 'New Jersey', 'short_name' => 'NJ'],
        ],
    ]);
    fakeGazetteer(new Municipality('3401732250', 'Hoboken', MunicipalityType::CountySubdivision, 'NJ'));

    $this->artisan('launchpad:anchor-locations', ['--site' => 'SPG'])
        ->doesntExpectOutputToContain('mailing town differs')
        ->assertSuccessful();
});
