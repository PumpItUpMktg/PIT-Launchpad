<?php

use App\Enums\MunicipalityType;
use App\Locations\CoverageRepair;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

function cvSite(): Site
{
    return Site::factory()->create(['brand_name' => 'SPG', 'corporate_state' => 'NJ']);
}

/** Insert a coverage row with a raw name, bypassing the model's cleaning mutator (simulates legacy data). */
function rawCoverage(Site $site, string $geoId, string $name, string $state = 'NJ', array $extra = []): void
{
    $area = CoverageArea::factory()->create(array_merge([
        'site_id' => $site->id, 'geo_id' => $geoId, 'state' => $state, 'type' => MunicipalityType::CountySubdivision,
    ], $extra));
    DB::table('coverage_areas')->where('id', $area->id)->update(['name' => $name]);
}

it('strips the numbered-list prefix from coverage names', function () {
    $site = cvSite();
    rawCoverage($site, '3402700001', '1, Abingdon');

    app(CoverageRepair::class)->repair($site, apply: true);

    $names = CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->pluck('name');
    expect($names)->toContain('Abingdon')->not->toContain('1, Abingdon');
});

it('removes out-of-territory coverage and prunes the sourcing county from locations', function () {
    $site = cvSite();
    $location = Location::factory()->create([
        'site_id' => $site->id,
        'county_geoids' => ['34027', '24025'], // NJ Morris + MD Harford
        'home_county_geoid' => '34027',
    ]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '3402700010', 'name' => 'Morristown', 'state' => 'NJ']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '2402500010', 'name' => 'Bel Air', 'state' => 'MD']);
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '2402500011', 'name' => 'Abingdon', 'state' => 'MD']);

    $report = app(CoverageRepair::class)->repair($site, apply: true);

    $states = CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->pluck('state');
    expect($states->all())->toBe(['NJ'])
        ->and($report['counties_pruned'])->toContain('24025');

    // The MD county is pruned from the location so a coverage recompute can't re-add it.
    expect($location->fresh()->county_geoids)->toBe(['34027']);
});

it('collapses intra-county duplicate towns, keeping the best row', function () {
    $site = cvSite();
    // Two "Bethlehem" in the same county (34095): a place (kept) and a county_subdivision (dropped).
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '3409500010', 'name' => 'Bethlehem', 'state' => 'NJ', 'type' => MunicipalityType::Place, 'population' => 4000]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '3409500011', 'name' => 'Bethlehem', 'state' => 'NJ', 'type' => MunicipalityType::CountySubdivision, 'population' => 900]);
    // A same-named town in a DIFFERENT county must be left alone.
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '3404100010', 'name' => 'Bethlehem', 'state' => 'NJ', 'type' => MunicipalityType::CountySubdivision]);

    app(CoverageRepair::class)->repair($site, apply: true);

    $rows = CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', 'Bethlehem')->get();
    expect($rows)->toHaveCount(2); // one per county
    $inCounty = $rows->firstWhere('geo_id', '3409500010');
    expect($inCounty)->not->toBeNull()
        ->and($inCounty->type)->toBe(MunicipalityType::Place); // the place row survived
});

it('cleans served_towns: prefix, out-of-territory, and duplicates', function () {
    $site = cvSite();
    $location = Location::factory()->create([
        'site_id' => $site->id,
        'served_towns' => [
            ['name' => '2, Morristown', 'state' => 'NJ', 'lat' => 40.8, 'lng' => -74.5, 'geocoded' => true],
            ['name' => 'Bel Air', 'state' => 'MD', 'lat' => 39.5, 'lng' => -76.3, 'geocoded' => true],   // out of territory
            ['name' => 'Morristown', 'state' => 'NJ', 'lat' => 40.8, 'lng' => -74.5, 'geocoded' => true], // duplicate of row 1 after cleaning
            ['name' => 'Denville', 'state' => 'NJ', 'lat' => 40.9, 'lng' => -74.5, 'geocoded' => true],
        ],
    ]);

    $report = app(CoverageRepair::class)->repair($site, apply: true);

    $towns = collect($location->fresh()->served_towns);
    expect($towns->pluck('name')->all())->toBe(['Morristown', 'Denville'])
        ->and($report['served']['out_of_state'])->toBe(1)
        ->and($report['served']['deduped'])->toBe(1)
        ->and($report['served']['prefix_cleaned'])->toBe(1);
});

it('is a no-op preview when apply is false', function () {
    $site = cvSite();
    rawCoverage($site, '2402500010', 'Bel Air', 'MD');

    $report = app(CoverageRepair::class)->repair($site, apply: false);

    expect($report['coverage']['out_of_state'])->toHaveCount(1)
        ->and(CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(1);
});

it('skips the out-of-territory pass when the site has no known state', function () {
    $site = Site::factory()->create(['brand_name' => 'Unknown', 'corporate_state' => null]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '2402500010', 'name' => 'Bel Air', 'state' => 'MD']);

    $report = app(CoverageRepair::class)->repair($site, apply: true);

    expect($report['territory'])->toBe([])
        ->and($report['coverage']['out_of_state'])->toBe([])
        ->and(CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->count())->toBe(1);
});
