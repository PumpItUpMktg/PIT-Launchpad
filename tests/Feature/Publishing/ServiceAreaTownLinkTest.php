<?php

use App\Enums\ContentKind;
use App\Enums\MunicipalityType;
use App\Enums\PageType;
use App\Enums\StandardPageType;
use App\Integrations\Census\County;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Site;
use App\Publishing\Blocks\ServiceAreaResolver;
use Illuminate\Support\Facades\Cache;

/** Mock the gazetteer so one county resolves by name (polygons unneeded — the town is a subdivision). */
function townLinkGazetteer(string $stateFips, string $geoId, string $name): void
{
    Cache::flush();
    $gaz = Mockery::mock(MunicipalityGazetteer::class);
    $gaz->shouldReceive('countiesInState')->andReturnUsing(fn (string $fips): array => $fips === $stateFips
        ? [new County($geoId, $name, substr($geoId, 0, 2), substr($geoId, 2))]
        : []);
    $gaz->shouldReceive('countyPolygons')->andReturn([]);
    app()->instance(MunicipalityGazetteer::class, $gaz);
}

it('links a served town to its own "{City}, {ST}"-titled location page, not the Areas page', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.test']);
    Location::factory()->create(['site_id' => $site->id, 'county_geoids' => ['42045']]);
    townLinkGazetteer('42', '42045', 'Delaware County');

    // The town, bare-named, as a county subdivision of 42045 (assigned by GEOID prefix — no polygon).
    CoverageArea::factory()->create([
        'site_id' => $site->id, 'name' => 'Haverford', 'state' => 'PA', 'type' => MunicipalityType::CountySubdivision,
        'geo_id' => '4204500000', 'lat' => 40.0, 'lng' => -75.3, 'size_tier' => 'large', 'population' => 48000,
    ]);

    // Its location page — titled "{City}, {ST}", the format LocationLandingFactory produces.
    Content::create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'title' => 'Haverford, PA', 'slug' => 'haverford-pa', 'version' => 1,
    ]);
    // The fallback target — a real "Areas we serve" page — which the town must NOT link to.
    Content::create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'standard_type' => StandardPageType::AreasWeServe->value,
        'title' => 'Areas We Serve', 'slug' => 'areas-we-serve', 'version' => 1,
    ]);

    $byCounty = app(ServiceAreaResolver::class)->byCounty($site->id);
    $haverford = collect($byCounty)->flatMap(fn (array $g): array => $g['cities'])
        ->firstWhere('label', 'Haverford');

    expect($haverford)->not->toBeNull()
        ->and($haverford['url'])->toBe('https://spg.test/haverford-pa')     // its own page
        ->and($haverford['url'])->not->toBe('https://spg.test/areas-we-serve');

    // The flat pills path resolves the same link (both share the town-name key).
    $flat = app(ServiceAreaResolver::class)->resolve($site->id);
    expect(collect($flat['cities'])->firstWhere('label', 'Haverford')['url'])
        ->toBe('https://spg.test/haverford-pa');
});

it('renders an unbuilt town as PLAIN TEXT (empty url), not a self-referencing Areas-page link', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.test']);
    Location::factory()->create(['site_id' => $site->id, 'county_geoids' => ['42045']]);
    townLinkGazetteer('42', '42045', 'Delaware County');

    CoverageArea::factory()->create([
        'site_id' => $site->id, 'name' => 'Marple', 'state' => 'PA', 'type' => MunicipalityType::CountySubdivision,
        'geo_id' => '4204500001', 'lat' => 40.0, 'lng' => -75.3, 'size_tier' => 'medium', 'population' => 23000,
    ]);
    // An Areas-we-serve page exists — the town must STILL render plain, not link to it.
    Content::create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'standard_type' => StandardPageType::AreasWeServe->value,
        'title' => 'Areas We Serve', 'slug' => 'areas-we-serve', 'version' => 1,
    ]);

    $byCounty = app(ServiceAreaResolver::class)->byCounty($site->id);
    $marple = collect($byCounty)->flatMap(fn (array $g): array => $g['cities'])->firstWhere('label', 'Marple');

    // Was the Areas-page fallback; now plain text so the list fills in as tiers get built and the link
    // plan has a real target to attach to (rule 3: this asserts the exact behavior that changed).
    expect($marple['url'])->toBe('');
});
