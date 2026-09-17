<?php

use App\GeoGrid\CountyOutlines;
use App\GeoGrid\TownOutlines;
use App\Integrations\Census\MockMunicipalityGazetteer;
use App\Integrations\Census\MunicipalityGazetteer;
use App\Jobs\WarmTownOutlines;
use App\Models\CoverageArea;
use App\Models\JobCounty;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use App\TownRank\TownRankBoard;
use App\TownRank\TownRankPoints;
use Illuminate\Support\Facades\Cache;

/** Two towns the Census has shapes for, one it does not; the county outline is known. */
function shapeSite(): array
{
    app()->instance(MunicipalityGazetteer::class, new MockMunicipalityGazetteer(polygons: [
        '34041' => [[['lat' => 40.95, 'lng' => -74.95], ['lat' => 40.95, 'lng' => -74.75], ['lat' => 40.75, 'lng' => -74.75], ['lat' => 40.75, 'lng' => -74.95]]],
        '3404128590' => [[['lat' => 40.87, 'lng' => -74.85], ['lat' => 40.87, 'lng' => -74.81], ['lat' => 40.83, 'lng' => -74.81], ['lat' => 40.83, 'lng' => -74.85]]],
        '3404143440' => [[['lat' => 40.82, 'lng' => -74.87], ['lat' => 40.82, 'lng' => -74.83], ['lat' => 40.78, 'lng' => -74.83], ['lat' => 40.78, 'lng' => -74.87]]],
    ]));
    JobCounty::factory()->create(['county_geoid' => '34041', 'name' => 'Warren', 'state' => 'NJ']);
    $site = Site::factory()->create(['domain_url' => 'https://spg.com']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.85, 'lng' => -74.83, 'home_county_geoid' => '34041', 'county_geoids' => []]);
    $towns = [];
    foreach ([['Hackettstown', '3404128590', 40.85], ['Mansfield', '3404143440', 40.80], ['Shapeless', '3404199999', 40.78]] as [$name, $geoId, $lat]) {
        $towns[$name] = CoverageArea::factory()->create(['site_id' => $site->id, 'name' => $name, 'state' => 'NJ', 'geo_id' => $geoId,
            'population' => 9000, 'lat' => $lat, 'lng' => -74.83, 'source_location_ids' => [$loc->id]]);
    }
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service', 'track_town_rank' => true]);
    $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => 'town_query', 'status' => 'complete',
        'points_count' => 3, 'found_count' => 1, 'scanned_at' => now()]);
    foreach ([['Hackettstown', 2], ['Mansfield', null], ['Shapeless', 5]] as [$name, $rank]) {
        $t = $towns[$name];
        TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'coverage_area_id' => $t->id, 'geo_id' => $t->geo_id,
            'label' => $name, 'state' => 'NJ', 'lat' => $t->lat, 'lng' => $t->lng, 'query' => 'q', 'rank' => $rank, 'collected_at' => now()]);
    }

    return ['site' => $site, 'keyword' => $kw, 'towns' => $towns];
}

it('draws the county outline and only the town shapes already cached — the render never fetches', function () {
    $f = shapeSite();

    // Nothing cached: the map is county outline + dots, and the page made no gazetteer call for towns.
    $board = app(TownRankBoard::class)->for($f['site'], (string) $f['keyword']->id, 'town_query');

    expect($board['town_paths'])->toBe([])
        ->and($board['outlines'])->toHaveCount(1)
        ->and($board['outlines'][0]['label'])->toBe('Warren County, NJ')
        ->and($board['outlines'][0]['paths'])->not->toBeEmpty()
        ->and($board['markers'])->toHaveCount(3);

    // Warm the boundaries off-request, then the same render draws the two shapes the Census knows.
    app(WarmTownOutlines::class, ['siteId' => (string) $f['site']->id])
        ->handle(app(TownRankPoints::class), app(TownOutlines::class), app(CountyOutlines::class));
    app(TownRankPoints::class)->forget();

    $board = app(TownRankBoard::class)->for($f['site'], (string) $f['keyword']->id, 'town_query');
    $shaped = array_keys($board['town_paths']);

    expect($shaped)->toHaveCount(2)
        ->and($shaped)->toContain((string) $f['towns']['Hackettstown']->id)
        ->and($shaped)->toContain((string) $f['towns']['Mansfield']->id)
        // The town with no Census shape keeps its dot rather than vanishing.
        ->and($shaped)->not->toContain((string) $f['towns']['Shapeless']->id)
        ->and($board['markers'])->toHaveCount(3);
});

it('reports what is left to warm, and finds nothing to do once the cache is full', function () {
    $f = shapeSite();
    $outlines = app(TownOutlines::class);
    $geoIds = ['3404128590', '3404143440', '3404199999'];

    expect($outlines->missing($geoIds))->toHaveCount(3);

    $outlines->for($geoIds);   // fetch + cache

    // Only the town the Census has no shape for is still "missing" — it will never cache, and costs one
    // lookup per pass rather than blocking a render.
    expect($outlines->missing($geoIds))->toBe(['3404199999']);
});

it('cache-only reads never call the gazetteer', function () {
    shapeSite();
    Cache::flush();
    $spy = new class extends MockMunicipalityGazetteer
    {
        public int $calls = 0;

        public function townPolygons(array $geoIds): array
        {
            $this->calls++;

            return parent::townPolygons($geoIds);
        }
    };
    app()->instance(MunicipalityGazetteer::class, $spy);

    expect(app(TownOutlines::class)->for(['3404128590'], fetchMissing: false))->toBe([])
        ->and($spy->calls)->toBe(0);

    app(TownOutlines::class)->for(['3404128590']);
    expect($spy->calls)->toBe(1);
});
