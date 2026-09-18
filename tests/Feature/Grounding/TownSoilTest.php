<?php

use App\Local\Grounding\TownSoilFacts;
use App\Local\Grounding\TownSoilSync;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Site;
use App\Models\TownSoilDrainage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Soil Data Access answers as a bare table of [class, intersected area] — Warrington's real shape. */
function sdaTable(array $rows): array
{
    return ['Table' => array_map(fn (array $r): array => [$r[0], (string) $r[1]], $rows)];
}

function soilSite(): array
{
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.example']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.3, 'lng' => -75.1]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Warrington', 'state' => 'PA',
        'geo_id' => '4201781048', 'population' => 25597, 'source_location_ids' => [$loc->id]]);

    // The boundary cache the gazetteer fills — an ArcGIS exterior ring winds clockwise.
    Cache::put('lp.town_outline.4201781048', [[
        ['lat' => 40.22, 'lng' => -75.18], ['lat' => 40.28, 'lng' => -75.18],
        ['lat' => 40.28, 'lng' => -75.12], ['lat' => 40.22, 'lng' => -75.12],
        ['lat' => 40.22, 'lng' => -75.18],
    ]], now()->addDay());

    return [$site, $loc];
}

it('weights drainage by AREA and sends the town\'s own boundary', function () {
    // A real Soil Data Access answer (the live run over a box near Warrington), areas verbatim.
    Http::fake(['*sdmdataaccess*' => Http::response(sdaTable([
        ['Somewhat poorly drained', 0.00116994547352078],
        [null, 0.00101530032225128],                       // unclassed ground — never a drainage answer
        ['Moderately well drained', 0.000669306933104963],
        ['Poorly drained', 0.000426155802415451],
        ['Well drained', 0.000278946295793503],
        ['Somewhat excessively drained', 0.0000403451724650949],
    ]))]);
    [$site] = soilSite();

    $result = app(TownSoilSync::class)->forSite($site);

    expect($result)->toMatchArray(['fetched' => 1, 'surveyed' => 1, 'unsurveyed' => 0]);

    Http::assertSent(function ($request): bool {
        $sql = (string) ($request->data()['query'] ?? '');

        // The town's own polygon, intersected — not a bounding box, and not a count of map units.
        return str_contains($sql, 'STIntersection')
            && str_contains($sql, 'multipolygon')
            && str_contains($sql, '-75.180000 40.220000')
            && str_contains($sql, 'GROUP BY m.drclassdcd');
    });

    $row = TownSoilDrainage::query()->where('geo_id', '4201781048')->sole();
    expect($row->surveyed)->toBeTrue()
        ->and($row->dominant)->toBe('Somewhat poorly drained')
        // Somewhat poorly + poorly, over the CLASSED ground only — the unclassed row is not in the
        // denominator, so a town half-covered by water is not reported as half-draining-well.
        ->and(round((float) $row->poorly_share, 2))->toBe(0.62)
        ->and($row->classes)->toHaveCount(5)    // the null-class row is not a class
        ->and($row->water_share)->toBe(0.0);
});

it('keeps "not surveyed" apart from "drains well"', function () {
    Http::fake(['*sdmdataaccess*' => Http::response(['Table' => []])]);
    [$site] = soilSite();

    app(TownSoilSync::class)->forSite($site);

    $row = TownSoilDrainage::query()->sole();
    expect($row->surveyed)->toBeFalse()->and($row->poorly_share)->toBeNull();
    // Silence is not a finding: an unsurveyed town says nothing at all.
    expect(app(TownSoilFacts::class)->for($row))->toBe([]);
});

it('speaks for wet ground and for free-draining ground, and not for the middle', function () {
    $facts = app(TownSoilFacts::class);

    $wet = new TownSoilDrainage(['name' => 'Warrington', 'surveyed' => true, 'poorly_share' => 0.66,
        'dominant' => 'Somewhat poorly drained', 'classes' => []]);
    expect($facts->for($wet))->toBe(['About 66% of the ground in Warrington is mapped somewhat poorly to poorly drained (USDA soil survey) — soil that holds water rather than shedding it.']);

    $dry = new TownSoilDrainage(['name' => 'Sandyville', 'surveyed' => true, 'poorly_share' => 0.04,
        'dominant' => 'Well drained', 'classes' => []]);
    expect($facts->for($dry))->toBe(['The ground across most of Sandyville drains freely — the dominant soil is mapped well drained (USDA soil survey).']);

    // Mixed ground: true of most places, so it distinguishes nothing.
    $mixed = new TownSoilDrainage(['name' => 'Middleford', 'surveyed' => true, 'poorly_share' => 0.25,
        'dominant' => 'Moderately well drained', 'classes' => []]);
    expect($facts->for($mixed))->toBe([]);

    // The ground, never the reader's lot.
    expect(implode(' ', $facts->for($wet)))->not->toContain('your');
});

it('drops holes rather than counting them as land', function () {
    Http::fake(['*sdmdataaccess*' => Http::response(sdaTable([['Poorly drained', 1.0]]))]);
    $site = Site::factory()->create();
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Ringed', 'geo_id' => '4201700002',
        'population' => 100, 'source_location_ids' => []]);
    Cache::put('lp.town_outline.4201700002', [
        // Exterior — the winding real TIGERweb rings have (verified: a positive signed area).
        [['lat' => 40.0, 'lng' => -75.0], ['lat' => 40.1, 'lng' => -75.0], ['lat' => 40.1, 'lng' => -74.9], ['lat' => 40.0, 'lng' => -74.9], ['lat' => 40.0, 'lng' => -75.0]],
        // … and a hole, wound the other way (the same points, reversed).
        [['lat' => 40.04, 'lng' => -74.96], ['lat' => 40.04, 'lng' => -74.94], ['lat' => 40.06, 'lng' => -74.94], ['lat' => 40.06, 'lng' => -74.96], ['lat' => 40.04, 'lng' => -74.96]],
    ], now()->addDay());

    app(TownSoilSync::class)->forSite($site);

    Http::assertSent(function ($request): bool {
        $sql = (string) ($request->data()['query'] ?? '');

        // The exterior ring is sent and the hole is not — a hole sent as land would count ground the
        // town does not contain.
        return str_contains($sql, '-75.000000 40.000000')
            && ! str_contains($sql, '-74.960000 40.040000');
    });
});

it('never counts seabed as ground: a coastal town is described by its LAND', function () {
    // Brooklyn's real shape from the first production run: its largest mapped class is Subaqueous —
    // soil under the harbour. Counting that as ground had it "draining freely", which it does not.
    Http::fake(['*sdmdataaccess*' => Http::response(sdaTable([
        ['Subaqueous', 6.0],
        ['Poorly drained', 1.0],
        ['Well drained', 3.0],
    ]))]);
    $site = Site::factory()->create();
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Brooklyn', 'state' => 'NY',
        'geo_id' => '3604710022', 'population' => 2600000, 'source_location_ids' => []]);
    Cache::put('lp.town_outline.3604710022', [[
        ['lat' => 40.6, 'lng' => -74.0], ['lat' => 40.7, 'lng' => -74.0],
        ['lat' => 40.7, 'lng' => -73.9], ['lat' => 40.6, 'lng' => -73.9], ['lat' => 40.6, 'lng' => -74.0],
    ]], now()->addDay());

    app(TownSoilSync::class)->forSite($site);

    $row = TownSoilDrainage::query()->where('geo_id', '3604710022')->sole();
    expect($row->water_share)->toBe(0.6)                 // six parts of ten are harbour
        ->and($row->dominant)->toBe('Well drained')       // the dominant LAND class, not the seabed
        ->and(round((float) $row->poorly_share, 2))->toBe(0.25)   // 1 of the 4 land parts
        ->and(collect($row->classes)->pluck('class')->all())->not->toContain('Subaqueous');
});

it('a town that is all water is surveyed and still says nothing', function () {
    Http::fake(['*sdmdataaccess*' => Http::response(sdaTable([['Subaqueous', 9.0]]))]);
    $site = Site::factory()->create();
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Bay', 'geo_id' => '3604700099',
        'population' => 10, 'source_location_ids' => []]);
    Cache::put('lp.town_outline.3604700099', [[
        ['lat' => 40.5, 'lng' => -74.1], ['lat' => 40.6, 'lng' => -74.1],
        ['lat' => 40.6, 'lng' => -74.0], ['lat' => 40.5, 'lng' => -74.0], ['lat' => 40.5, 'lng' => -74.1],
    ]], now()->addDay());

    app(TownSoilSync::class)->forSite($site);

    $row = TownSoilDrainage::query()->where('geo_id', '3604700099')->sole();
    expect($row->surveyed)->toBeTrue()          // the survey covers it …
        ->and($row->water_share)->toBe(1.0)
        ->and($row->poorly_share)->toBeNull()   // … and there is no ground to describe
        ->and(app(TownSoilFacts::class)->for($row))->toBe([]);
});
