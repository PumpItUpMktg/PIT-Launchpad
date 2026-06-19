<?php

use App\Enums\MunicipalityType;
use App\Enums\SpokeGranularity;
use App\Enums\SpokeStatus;
use App\Enums\SpokeTag;
use App\Integrations\DataForSeo\DataForSeoClient;
use App\Integrations\DataForSeo\DataForSeoLocations;
use App\Interview\Volume\VolumeException;
use App\Interview\Volume\VolumeGrounder;
use App\Locations\Dma\DmaTable;
use App\Locations\Dma\MetroResolver;
use App\Models\CoverageArea;
use App\Models\Scopes\SiteScope;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

const NY_DMA = 1023191;
const PHL_DMA = 1022000;

/** The DMA Region + State catalog rows the metros resolve against. */
const FAKE_CATALOG = [
    ['location_code' => NY_DMA, 'location_name' => 'New York, NY, United States', 'location_type' => 'DMA Region'],
    ['location_code' => PHL_DMA, 'location_name' => 'Philadelphia, PA, United States', 'location_type' => 'DMA Region'],
    ['location_code' => 21138, 'location_name' => 'New Jersey,United States', 'location_type' => 'State'],
];

/** Per-metro fake volumes keyed by the resolved DataForSEO location_code. */
const FAKE_VOLUMES = [
    NY_DMA => ['sump pump' => 20, 'sump pump installation' => 300, 'niche thing' => 20],
    PHL_DMA => ['sump pump' => 10, 'sump pump installation' => 100, 'niche thing' => 10],
];

/**
 * Fake BOTH endpoints: the Google Ads locations catalog (for code resolution) and the
 * search-volume call (keyed by location_code). `$volumes`/`$catalog` override per test.
 */
function fakeSearchVolume(?array $volumes = null, ?array $catalog = null): void
{
    $volumes ??= FAKE_VOLUMES;
    $catalog ??= FAKE_CATALOG;

    Http::fake(function ($request) use ($volumes, $catalog) {
        if (str_contains($request->url(), '/keywords_data/google_ads/locations')) {
            return Http::response(['status_code' => 20000, 'tasks' => [['status_code' => 20000, 'result' => $catalog]]]);
        }

        $code = (int) ($request->data()[0]['location_code'] ?? 0);
        if (! array_key_exists($code, $volumes)) {
            return Http::response(['status_code' => 20000, 'tasks' => [['status_code' => 40501, 'status_message' => 'no location']]]);
        }
        $result = [];
        foreach ($volumes[$code] as $kw => $v) {
            $result[] = ['keyword' => $kw, 'search_volume' => $v];
        }

        return Http::response(['status_code' => 20000, 'tasks' => [['status_code' => 20000, 'result' => $result]]]);
    });
}

function grounderFor(int $threshold = 50): VolumeGrounder
{
    $resolver = new MetroResolver(new DmaTable(
        countyToDma: ['34003' => 'New York,NY,United States', '34005' => 'Philadelphia,PA,United States'],
        stateToLocation: ['NJ' => 'New Jersey,United States'],
    ));
    $client = new DataForSeoClient(app(Factory::class), 'x', 'y', 'https://api.dataforseo.com', 30);
    $locations = new DataForSeoLocations($client, app(Cache::class), 'US');

    return new VolumeGrounder($resolver, $client, $locations, 'en', $threshold);
}

function groundedSite(): Site
{
    $site = Site::factory()->create();
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '3400312345', 'type' => MunicipalityType::CountySubdivision, 'state' => 'NJ']); // NY
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '3400599999', 'type' => MunicipalityType::CountySubdivision, 'state' => 'NJ']); // Philly

    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Sump Pumps', 'is_pillar' => true, 'name' => 'Sump Pumps', 'head_keyword' => 'sump pump', 'tag' => SpokeTag::Core, 'status' => SpokeStatus::Candidate, 'granularity' => SpokeGranularity::OwnPage, 'volume' => null]);
    Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Sump Pumps', 'is_pillar' => false, 'name' => 'Sump Pump Installation', 'head_keyword' => 'sump pump installation', 'tag' => SpokeTag::Core, 'status' => SpokeStatus::Candidate, 'granularity' => SpokeGranularity::OwnPage, 'volume' => null]);
    Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Sump Pumps', 'is_pillar' => false, 'name' => 'Niche Thing', 'head_keyword' => 'niche thing', 'tag' => SpokeTag::Adjacent, 'status' => SpokeStatus::Candidate, 'granularity' => SpokeGranularity::OwnPage, 'volume' => null]);
    Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Out of Lane', 'is_pillar' => false, 'name' => 'General Plumbing', 'head_keyword' => null, 'tag' => SpokeTag::Fringe, 'status' => SpokeStatus::Candidate]);

    return $site;
}

function spoke(Site $site, string $name): Spoke
{
    return Spoke::withoutGlobalScope(SiteScope::class)->where('site_id', $site->id)->where('name', $name)->first();
}

test('it sums volume across covered metros and writes the breakdown + timestamp', function () {
    fakeSearchVolume();
    $site = groundedSite();

    grounderFor()->ground($site);

    $install = spoke($site, 'Sump Pump Installation');
    expect($install->volume)->toBe(400) // 300 NY + 100 Philly
        ->and($install->volume_breakdown)->toBe(['New York,NY' => 300, 'Philadelphia,PA' => 100])
        ->and($install->volume_at)->not->toBeNull();
});

test('a low-volume non-pillar spoke is advised to fold; a pillar never folds', function () {
    fakeSearchVolume();
    $site = groundedSite();

    grounderFor(threshold: 50)->ground($site);

    expect(spoke($site, 'Niche Thing')->granularity)->toBe(SpokeGranularity::Folded)        // 30 < 50
        ->and(spoke($site, 'Sump Pump Installation')->granularity)->toBe(SpokeGranularity::OwnPage) // 400 ≥ 50
        ->and(spoke($site, 'Sump Pumps')->volume)->toBe(30)                                  // pillar low...
        ->and(spoke($site, 'Sump Pumps')->granularity)->toBe(SpokeGranularity::OwnPage);     // ...but pillars are exempt
});

test('fringe / no-keyword spokes are left untouched', function () {
    fakeSearchVolume();
    $site = groundedSite();

    grounderFor()->ground($site);

    $fringe = spoke($site, 'General Plumbing');
    expect($fringe->volume)->toBeNull()
        ->and($fringe->volume_at)->toBeNull();
});

test('a metro that does not resolve to a location_code is skipped, not fatal', function () {
    // Catalog without Philadelphia → it resolves to null and is skipped; NY still grounds.
    fakeSearchVolume(
        volumes: [NY_DMA => ['sump pump installation' => 250]],
        catalog: [['location_code' => NY_DMA, 'location_name' => 'New York, NY, United States', 'location_type' => 'DMA Region']],
    );
    $site = groundedSite();

    $result = grounderFor()->ground($site);

    expect(collect($result->skippedMetros)->pluck('name'))->toContain('Philadelphia,PA')
        ->and(spoke($site, 'Sump Pump Installation')->volume)->toBe(250); // NY only
});

test('two coverage areas in the same DMA are queried and counted once (no double-cover)', function () {
    $resolver = new MetroResolver(new DmaTable(
        countyToDma: ['34003' => 'New York,NY,United States', '34013' => 'New York,NY,United States'],
        stateToLocation: [],
    ));

    $volumeCalls = 0;
    Http::fake(function ($request) use (&$volumeCalls) {
        if (str_contains($request->url(), '/keywords_data/google_ads/locations')) {
            return Http::response(['status_code' => 20000, 'tasks' => [['status_code' => 20000, 'result' => [
                ['location_code' => NY_DMA, 'location_name' => 'New York, NY, United States', 'location_type' => 'DMA Region'],
            ]]]]);
        }
        $volumeCalls++;

        return Http::response(['status_code' => 20000, 'tasks' => [['status_code' => 20000, 'result' => [
            ['keyword' => 'sump pump installation', 'search_volume' => 300],
        ]]]]);
    });

    $site = Site::factory()->create();
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '3400312345', 'type' => MunicipalityType::CountySubdivision, 'state' => 'NJ']); // 34003 → NY
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '3401354321', 'type' => MunicipalityType::CountySubdivision, 'state' => 'NJ']); // 34013 → NY
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Sump Pumps', 'name' => 'Sump Pump Installation', 'head_keyword' => 'sump pump installation', 'tag' => SpokeTag::Core, 'status' => SpokeStatus::Candidate, 'granularity' => SpokeGranularity::OwnPage]);

    $client = new DataForSeoClient(app(Factory::class), 'x', 'y', 'https://api.dataforseo.com', 30);
    $locations = new DataForSeoLocations($client, app(Cache::class), 'US');
    (new VolumeGrounder($resolver, $client, $locations, 'en', 50))->ground($site);

    expect($volumeCalls)->toBe(1) // the shared DMA is queried exactly once
        ->and(spoke($site, 'Sump Pump Installation')->volume)->toBe(300); // counted once, not 600
});

test('it throws when there are no covered metros', function () {
    fakeSearchVolume();
    $site = Site::factory()->create();
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'head_keyword' => 'x']);

    grounderFor()->ground($site);
})->throws(VolumeException::class);

test('it throws when there are no candidate spokes', function () {
    fakeSearchVolume();
    $site = Site::factory()->create();
    CoverageArea::factory()->create(['site_id' => $site->id, 'geo_id' => '3400312345', 'type' => MunicipalityType::CountySubdivision, 'state' => 'NJ']);

    grounderFor()->ground($site);
})->throws(VolumeException::class);
