<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\MunicipalityType;
use App\Enums\PageType;
use App\Integrations\Census\County;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Site;
use App\Publishing\Blocks\ServiceAreaResolver;
use Illuminate\Support\Facades\Cache;

/** One county resolving by name; polygons unneeded (the town is a subdivision, assigned by GEOID prefix). */
function geoLinkGazetteer(string $stateFips, string $geoId, string $name): void
{
    Cache::flush();
    $gaz = Mockery::mock(MunicipalityGazetteer::class);
    $gaz->shouldReceive('countiesInState')->andReturnUsing(fn (string $fips): array => $fips === $stateFips
        ? [new County($geoId, $name, substr($geoId, 0, 2), substr($geoId, 2))]
        : []);
    $gaz->shouldReceive('countyPolygons')->andReturn([]);
    app()->instance(MunicipalityGazetteer::class, $gaz);
}

it('links a served town to its page by GEO id even when the page TITLE differs from the coverage name', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.test']);
    Location::factory()->create(['site_id' => $site->id, 'county_geoids' => ['42045']]);
    geoLinkGazetteer('42', '42045', 'Delaware County');

    // Coverage names the town "Haverford"; its census geo_id is 4204500000.
    CoverageArea::factory()->create([
        'site_id' => $site->id, 'name' => 'Haverford', 'state' => 'PA', 'type' => MunicipalityType::CountySubdivision,
        'geo_id' => '4204500000', 'lat' => 40.0, 'lng' => -75.3, 'size_tier' => 'large', 'population' => 48000,
    ]);

    // Its page is ANCHORED to the same geo_id but titled differently — a bare-name join would MISS it;
    // the GEOID join must still resolve the link.
    Content::create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'title' => 'Haverford Township, PA', 'slug' => 'haverford-township-pa', 'geo_id' => '4204500000', 'version' => 1,
        // Live (published + pushed) — the gate only links pages that are actually on the site.
        'status' => ContentStatus::Published, 'wp_post_id' => 602,
    ]);

    $byCounty = app(ServiceAreaResolver::class)->byCounty($site->id);
    $haverford = collect($byCounty)->flatMap(fn (array $g): array => $g['cities'])->firstWhere('label', 'Haverford');

    expect($haverford)->not->toBeNull()
        ->and($haverford['url'])->toBe('https://spg.test/haverford-township-pa'); // linked by GEO, not by name

    // The flat pills path resolves the same page by geo too.
    $flat = app(ServiceAreaResolver::class)->resolve($site->id);
    expect(collect($flat['cities'])->firstWhere('label', 'Haverford')['url'])
        ->toBe('https://spg.test/haverford-township-pa');
});
