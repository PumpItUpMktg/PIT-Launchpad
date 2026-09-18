<?php

use App\Integrations\Census\HousingStats;
use App\Local\Grounding\TownHousingFacts;
use App\Local\Grounding\TownHousingSync;
use App\Models\CensusHousing;
use App\Models\CoverageArea;
use App\Models\Location;
use App\Models\Site;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

/** One ACS answer shaped exactly as the API returns it: a header row, then one row per geography. */
function acsRows(): array
{
    return [
        ['NAME', 'B25035_001E', 'B25003_001E', 'B25003_002E', 'B25024_001E', 'B25024_002E', 'B25024_003E', 'B25034_009E', 'B25034_010E', 'B25034_011E', 'state', 'county', 'county subdivision'],
        // Old stock: median 1948, 82% owner-occupied, 90% single-family, 60% built before 1960.
        ['Warrington township, Bucks County, Pennsylvania', '1948', '1000', '820', '1100', '950', '40', '300', '160', '200', '42', '017', '81720'],
        // New stock: median 2005, and a suppressed median on the third to prove sentinels are dropped.
        ['Newtown township, Bucks County, Pennsylvania', '2005', '1000', '800', '1000', '900', '0', '20', '10', '10', '42', '017', '54656'],
        ['Suppressed township, Bucks County, Pennsylvania', '-666666666', '0', '0', '0', '0', '0', '0', '0', '0', '42', '017', '99999'],
    ];
}

function housingSite(): Site
{
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.com']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.3, 'lng' => -75.1]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Warrington', 'state' => 'PA', 'geo_id' => '4201781720',
        'population' => 25597, 'source_location_ids' => [$loc->id]]);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Newtown', 'state' => 'PA', 'geo_id' => '4201754656',
        'population' => 19000, 'source_location_ids' => [$loc->id]]);

    return $site;
}

function bindAcs(string $key = 'test-key', string $year = '2023'): void
{
    app()->bind(HousingStats::class, fn () => new HousingStats(app(Factory::class), app('cache.store'), $key, $year));
}

it('fetches every town in a county in one request and stores the raw counts', function () {
    Http::fake(['*/2023/acs/acs5*' => Http::response(acsRows())]);
    bindAcs();
    $site = housingSite();

    $result = app(TownHousingSync::class)->forSite($site);

    expect($result)->toMatchArray(['towns' => 2, 'written' => 2, 'missing' => [], 'requests' => 1]);
    Http::assertSentCount(1);   // 54 towns in a county would still be one call

    $warrington = CensusHousing::query()->where('geo_id', '4201781720')->sole();
    expect($warrington->name)->toBe('Warrington')   // the town's own name, not the ACS's long label
        ->and($warrington->median_year_built)->toBe(1948)
        ->and($warrington->single_family_units)->toBe(990)   // 1-unit detached + attached
        ->and($warrington->pre_1960_units)->toBe(660)        // 1950s + 1940s + 1939-or-earlier
        ->and($warrington->ownerOccupiedShare())->toBe(0.82)
        ->and(round((float) $warrington->pre1960Share(), 2))->toBe(0.6);

    // Already held → a second run fetches nothing.
    expect(app(TownHousingSync::class)->forSite($site))->toMatchArray(['fetched' => 0, 'requests' => 0]);
});

it('drops ACS suppression sentinels rather than storing them as numbers', function () {
    Http::fake(['*/2023/acs/acs5*' => Http::response(acsRows())]);
    bindAcs();
    $rows = app(HousingStats::class)->forCountySubdivisions('42', '017');

    expect($rows['4201799999']['median_year_built'])->toBeNull()   // -666666666 is not a year
        ->and($rows['4201799999']['total_units'])->toBe(0);
});

it('degrades to nothing without an API key instead of erroring', function () {
    Http::fake();
    bindAcs('');
    $site = housingSite();

    // A town the ACS never returned is NAMED, so the bad GEOID can be looked at — not just counted.
    $missing = array_column(app(TownHousingSync::class)->forSite($site)['missing'], 'geo_id');
    sort($missing);
    expect($missing)->toBe(['4201754656', '4201781720']);
    Http::assertNothingSent();
});

it('states only the numbers that carry information, and never what a house is made of', function () {
    $facts = app(TownHousingFacts::class);

    $old = new CensusHousing(['name' => 'Warrington', 'acs_year' => '2023', 'median_year_built' => 1948,
        'occupied_units' => 1000, 'owner_occupied_units' => 820, 'total_units' => 1100, 'single_family_units' => 990, 'pre_1960_units' => 660]);
    $lines = $facts->for($old);

    expect($lines)->toHaveCount(4)
        ->and($lines[0])->toBe('The median home in Warrington was built in 1948 (Census ACS 2023).')
        ->and($lines[1])->toBe('About 60% of the housing stock in Warrington was built before 1960.')
        ->and($lines[2])->toBe('82% of occupied homes in Warrington are owner-occupied.')
        ->and($lines[3])->toBe('90% of homes in Warrington are single-family houses.');
    // The Census says how old the stock is; it does not say what is under any one house.
    expect(implode(' ', $lines))->not->toContain('fieldstone')->not->toContain('galvanized');

    // A middling town says nothing about tenure or mix — half-and-half is not a fact worth a sentence.
    $middling = new CensusHousing(['name' => 'Middleton', 'acs_year' => '2023', 'median_year_built' => 1975,
        'occupied_units' => 1000, 'owner_occupied_units' => 600, 'total_units' => 1000, 'single_family_units' => 600, 'pre_1960_units' => 200]);
    expect($facts->for($middling))->toBe(['The median home in Middleton was built in 1975 (Census ACS 2023).']);

    expect($facts->for(null))->toBe([]);
});

it('names the town whose stored GEOID the ACS does not know', function () {
    Http::fake(['*/2023/acs/acs5*' => Http::response(acsRows())]);
    bindAcs();
    $site = housingSite();
    // A town carrying a GEOID that is not in its county's current ACS list — the South Orange case.
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Ghost Town', 'state' => 'PA',
        'geo_id' => '4201700000', 'population' => 1, 'source_location_ids' => []]);

    $result = app(TownHousingSync::class)->forSite($site);

    expect($result['missing'])->toBe([['name' => 'Ghost Town', 'geo_id' => '4201700000']])
        ->and($result['written'])->toBe(2);
});

it('reports whether the ACS answered at all, apart from whether it knew the town', function () {
    Http::fake(['*/2023/acs/acs5*' => Http::response(acsRows())]);
    bindAcs();
    $site = housingSite();
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'South Orange', 'state' => 'NJ',
        'geo_id' => '3401369270', 'population' => 16198, 'source_location_ids' => []]);

    $result = app(TownHousingSync::class)->forSite($site);

    // The API answered — with rows — it simply had no row for that GEOID. Those are different faults, and
    // only the first is a key problem worth telling the operator to go check.
    expect($result['rows'])->toBe(3)
        ->and($result['missing'])->toBe([['name' => 'South Orange', 'geo_id' => '3401369270']]);

    // A silent API: the ACS answers with nothing at all. A fresh client on a different vintage keeps the
    // cache above out of it, so this exercises the fetch rather than the memo.
    Http::fake(['*/2021/acs/acs5*' => Http::response([])]);
    bindAcs(year: '2021');
    expect(app(TownHousingSync::class)->forSite($site, force: true)['rows'])->toBe(0);
});
