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

/**
 * @param  array<string, list<array{geoId: string, name: string}>>  $counties  stateFips => counties
 * @param  array<string, list<list<array{lat: float, lng: float}>>>  $polygons  geoId => rings
 */
function disambigGazetteer(array $counties, array $polygons = []): void
{
    Cache::flush(); // county name/polygon caches are process-lived — start clean each test
    $gaz = Mockery::mock(MunicipalityGazetteer::class);
    $gaz->shouldReceive('countiesInState')->andReturnUsing(fn (string $stateFips): array => array_map(
        fn (array $c): County => new County($c['geoId'], $c['name'], substr($c['geoId'], 0, 2), substr($c['geoId'], 2)),
        $counties[$stateFips] ?? [],
    ));
    $gaz->shouldReceive('countyPolygons')->andReturnUsing(function (array $geoIds) use ($polygons): array {
        $out = [];
        foreach ($geoIds as $g) {
            if (isset($polygons[(string) $g])) {
                $out[] = ['geo_id' => (string) $g, 'name' => '', 'rings' => $polygons[(string) $g]];
            }
        }

        return $out;
    });
    app()->instance(MunicipalityGazetteer::class, $gaz);
}

/** All town labels the areas grid renders, flattened across counties. */
function disambigGridLabels(string $siteId): array
{
    return collect(app(ServiceAreaResolver::class)->byCounty($siteId))
        ->flatMap(fn (array $g): array => array_column($g['cities'], 'label'))
        ->all();
}

it('disambiguates two same-name municipalities in the same county by county then municipal type', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.test']);
    Location::factory()->create(['site_id' => $site->id, 'county_geoids' => ['34019']]);

    // A place and a county subdivision BOTH named "Bethlehem" that both resolve to Hunterdon County —
    // county alone can't split them, so the municipal descriptor breaks the tie.
    disambigGazetteer(
        counties: ['34' => [['geoId' => '34019', 'name' => 'Hunterdon County']]],
        polygons: ['34019' => [[
            ['lat' => 40.4, 'lng' => -75.2], ['lat' => 40.4, 'lng' => -74.8],
            ['lat' => 40.7, 'lng' => -74.8], ['lat' => 40.7, 'lng' => -75.2],
        ]]],
    );

    CoverageArea::factory()->create([
        'site_id' => $site->id, 'name' => 'Bethlehem', 'state' => 'NJ', 'type' => MunicipalityType::Place,
        'geo_id' => '3405050', 'lat' => 40.55, 'lng' => -75.0, 'size_tier' => 'medium', 'population' => 4000,
    ]);
    CoverageArea::factory()->create([
        'site_id' => $site->id, 'name' => 'Bethlehem', 'state' => 'NJ', 'type' => MunicipalityType::CountySubdivision,
        'geo_id' => '3401900011', 'lat' => 40.6, 'lng' => -74.95, 'size_tier' => 'small', 'population' => 900,
    ]);

    $labels = disambigGridLabels($site->id);

    expect($labels)->toContain('Bethlehem (Hunterdon County)')            // the place keeps the plain county form
        ->and($labels)->toContain('Bethlehem (Hunterdon County, Twp/Boro)') // the MCD gets the tie-breaking suffix
        ->and($labels)->not->toContain('Bethlehem');                       // never a bare, ambiguous label
});

it('disambiguates two same-name municipalities in different counties by county alone', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.test']);
    Location::factory()->create(['site_id' => $site->id, 'county_geoids' => ['34041', '34019']]);

    disambigGazetteer(counties: ['34' => [
        ['geoId' => '34041', 'name' => 'Warren County'],
        ['geoId' => '34019', 'name' => 'Hunterdon County'],
    ]]);

    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Washington', 'state' => 'NJ', 'type' => MunicipalityType::CountySubdivision, 'geo_id' => '3404100011']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Washington', 'state' => 'NJ', 'type' => MunicipalityType::CountySubdivision, 'geo_id' => '3401900012']);

    $labels = disambigGridLabels($site->id);

    expect($labels)->toContain('Washington (Warren County)')
        ->and($labels)->toContain('Washington (Hunterdon County)')
        ->and($labels)->not->toContain('Washington (Warren County, Twp/Boro)'); // county was enough; no suffix
});

it('leaves a unique town name exactly as it is', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.test']);
    Location::factory()->create(['site_id' => $site->id, 'county_geoids' => ['34019']]);
    disambigGazetteer(counties: ['34' => [['geoId' => '34019', 'name' => 'Hunterdon County']]]);

    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Flemington', 'state' => 'NJ', 'type' => MunicipalityType::CountySubdivision, 'geo_id' => '3401900021']);

    expect(disambigGridLabels($site->id))->toContain('Flemington');
});

it('falls back to the Areas-We-Serve page for a served town with no location page of its own', function () {
    $site = Site::factory()->create(['domain_url' => 'https://spg.test']);
    Location::factory()->create(['site_id' => $site->id, 'county_geoids' => ['34019']]);
    disambigGazetteer(counties: ['34' => [['geoId' => '34019', 'name' => 'Hunterdon County']]]);

    // A published "Areas We Serve" page is the fallback target.
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'standard_type' => StandardPageType::AreasWeServe,
        'slug' => 'areas-we-serve', 'title' => 'Areas We Serve',
    ]);
    // A town WITH its own published location page.
    Content::factory()->create([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'slug' => 'flemington-nj', 'title' => 'Flemington',
    ]);

    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Flemington', 'state' => 'NJ', 'type' => MunicipalityType::CountySubdivision, 'geo_id' => '3401900021']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Clinton', 'state' => 'NJ', 'type' => MunicipalityType::CountySubdivision, 'geo_id' => '3401900022']);

    $cities = collect(app(ServiceAreaResolver::class)->byCounty($site->id))->flatMap(fn (array $g): array => $g['cities']);

    expect($cities->firstWhere('label', 'Flemington')['url'])->toBe('https://spg.test/flemington-nj') // own page wins
        ->and($cities->firstWhere('label', 'Clinton')['url'])->toBe('https://spg.test/areas-we-serve'); // fallback
});
