<?php

use App\Enums\MunicipalityType;
use App\Locations\CoveragePanels;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\Site;

function seedArea(Site $site, array $attrs): CoverageArea
{
    return CoverageArea::create(array_merge([
        'site_id' => $site->id,
        'type' => MunicipalityType::CountySubdivision,
        'state' => 'NJ',
        'source' => 'county',
        'page_selected' => false,
    ], $attrs));
}

function panelsFor(Site $site): array
{
    $locations = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->orderBy('name')->get();

    return app(CoveragePanels::class)->build($site, $locations);
}

test('builds totals + per-location panels grouped by tier from persisted rows', function () {
    $site = Site::factory()->create();
    $a = Location::factory()->create(['site_id' => $site->id, 'name' => 'A']);
    $b = Location::factory()->create(['site_id' => $site->id, 'name' => 'B']);

    seedArea($site, ['geo_id' => '1', 'name' => 'Major1', 'population' => 60000, 'size_tier' => 'major', 'page_selected' => true, 'source_location_ids' => [$a->id]]);
    seedArea($site, ['geo_id' => '2', 'name' => 'Med1', 'population' => 18000, 'size_tier' => 'medium', 'source_location_ids' => [$a->id, $b->id]]); // shared → overlap
    seedArea($site, ['geo_id' => '3', 'name' => 'Ung1', 'population' => null, 'size_tier' => null, 'source_location_ids' => [$b->id]]);

    $vm = panelsFor($site);

    expect($vm['totals']['covered'])->toBe(3)
        ->and($vm['totals']['selected'])->toBe(1)
        ->and($vm['totals']['overlap'])->toBe(1)
        ->and($vm['totals']['tiers']['major'])->toBe(1)
        ->and($vm['totals']['tiers']['medium'])->toBe(1)
        ->and($vm['totals']['tiers']['ungrouped'])->toBe(1);

    expect($vm['panels'][$a->id]['town_count'])->toBe(2)        // Major1 + Med1
        ->and($vm['panels'][$a->id]['selected_count'])->toBe(1)  // Major1
        ->and($vm['panels'][$b->id]['town_count'])->toBe(2)      // Med1 + Ung1
        ->and($vm['panels'][$b->id]['selected_count'])->toBe(0)
        ->and($vm['panels'][$a->id]['groups']['major'][0]['name'])->toBe('Major1')
        ->and($vm['panels'][$b->id]['groups']['ungrouped'][0]['name'])->toBe('Ung1');
});

test('town groups are population-desc (name tiebreak) and the order is stable across a selection toggle', function () {
    $site = Site::factory()->create();
    $a = Location::factory()->create(['site_id' => $site->id, 'name' => 'A']);

    // all Major; 90k then a 60k tie resolved by name (Alpha before Beta)
    seedArea($site, ['geo_id' => '1', 'name' => 'Zeta', 'population' => 90000, 'size_tier' => 'major', 'source_location_ids' => [$a->id]]);
    seedArea($site, ['geo_id' => '2', 'name' => 'Alpha', 'population' => 60000, 'size_tier' => 'major', 'source_location_ids' => [$a->id]]);
    seedArea($site, ['geo_id' => '3', 'name' => 'Beta', 'population' => 60000, 'size_tier' => 'major', 'source_location_ids' => [$a->id]]);

    $order = fn () => collect(panelsFor($site)['panels'][$a->id]['groups']['major'])->pluck('name')->all();

    expect($order())->toBe(['Zeta', 'Alpha', 'Beta']);

    // flipping page_selected must NOT reorder (the click path uses the same canonical sort)
    CoverageArea::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('geo_id', '2')->update(['page_selected' => true]);

    expect($order())->toBe(['Zeta', 'Alpha', 'Beta']);
});

test('the selected total counts distinct persisted rows (no overlap double-count)', function () {
    $site = Site::factory()->create();
    $a = Location::factory()->create(['site_id' => $site->id, 'name' => 'A']);
    $b = Location::factory()->create(['site_id' => $site->id, 'name' => 'B']);

    // one shared, selected town — must count once in the site total even though two panels show it
    seedArea($site, ['geo_id' => '9', 'name' => 'Shared', 'population' => 60000, 'size_tier' => 'major', 'page_selected' => true, 'source_location_ids' => [$a->id, $b->id]]);

    $vm = panelsFor($site);

    expect($vm['totals']['covered'])->toBe(1)
        ->and($vm['totals']['selected'])->toBe(1)
        ->and($vm['panels'][$a->id]['selected_count'])->toBe(1)
        ->and($vm['panels'][$b->id]['selected_count'])->toBe(1);
});
