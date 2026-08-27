<?php

use App\Enums\ContentKind;
use App\Enums\ContentStatus;
use App\Enums\PageType;
use App\Metrics\UrlNormalizer;
use App\Models\Content;
use App\Models\CoverageArea;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\PageIndexState;
use App\Models\PositionSnapshot;
use App\Models\Site;
use App\Operate\LocationDashboard;
use App\Support\PublicUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function ldGbpLocation(Site $site, array $attrs = []): Location
{
    return Location::factory()->create(array_merge([
        'site_id' => $site->id, 'name' => 'Downtown',
        'gbp_url' => 'https://maps.google.com/?cid=123', 'place_id' => 'ChIJ_acme',
        'lat' => 40.7, 'lng' => -74.0,
    ], $attrs));
}

function ldClusterPage(Site $site, array $attrs): Content
{
    return Content::factory()->create(array_merge([
        'site_id' => $site->id, 'kind' => ContentKind::Page, 'page_type' => PageType::Location,
        'status' => ContentStatus::Published,
    ], $attrs));
}

function ldGscSnap(Site $site, string $path, string $key, int $value): void
{
    // Insert the way the real GSC provider does (raw builder, clean Y-m-d) — the model's `date` cast would
    // append a time component and break the string-range window query.
    DB::table('metric_snapshots')->insert([
        'id' => (string) Str::ulid(), 'site_id' => $site->id, 'provider' => 'gsc', 'metric_key' => $key,
        'dimension_type' => 'page', 'dimension_value' => $path,
        'period_grain' => 'day', 'period_date' => now()->toDateString(),
        'value_numeric' => $value, 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('resolves the cluster (hub + town pages) and counts live pages', function () {
    $site = Site::factory()->create();
    $location = ldGbpLocation($site);

    ldClusterPage($site, ['location_id' => $location->id, 'title' => 'Newark, NJ']);          // hub
    ldClusterPage($site, ['parent_location_id' => $location->id, 'title' => 'Belleville, NJ']); // town, live
    ldClusterPage($site, ['parent_location_id' => $location->id, 'title' => 'Nutley, NJ', 'status' => ContentStatus::Drafted]); // town, not live

    $d = app(LocationDashboard::class)->for($location->fresh());

    expect($d['inventory']['pages_total'])->toBe(3)
        ->and($d['inventory']['pages_live'])->toBe(2)          // hub + Belleville
        ->and($d['inventory']['hub_live'])->toBeTrue();
});

it('aggregates cluster GSC performance from the metric spine', function () {
    $site = Site::factory()->create();
    $location = ldGbpLocation($site);
    $hub = ldClusterPage($site, ['location_id' => $location->id, 'title' => 'Newark, NJ', 'slug' => 'newark-nj']);

    $path = UrlNormalizer::path(PublicUrl::forContent($site->domain_url, $hub) ?? '/newark-nj');
    ldGscSnap($site, $path, 'impressions', 400);
    ldGscSnap($site, $path, 'clicks', 25);

    $d = app(LocationDashboard::class)->for($location->fresh());

    expect($d['performance']['impressions'])->toBe(400)
        ->and($d['performance']['clicks'])->toBe(25)
        ->and($d['performance']['pages'][0]['title'])->toBe('Newark, NJ');
});

it('reports town + population coverage, crediting population to published towns', function () {
    $site = Site::factory()->create();
    $location = ldGbpLocation($site);
    ldClusterPage($site, ['parent_location_id' => $location->id, 'title' => 'Belleville, NJ']); // published

    CoverageArea::create([
        'site_id' => $site->id, 'geo_id' => '3401', 'name' => 'Belleville', 'type' => 'place', 'state' => 'NJ',
        'source_location_ids' => [$location->id], 'population' => 36000, 'page_selected' => true,
    ]);
    CoverageArea::create([
        'site_id' => $site->id, 'geo_id' => '3402', 'name' => 'Nutley', 'type' => 'place', 'state' => 'NJ',
        'source_location_ids' => [$location->id], 'population' => 30000, 'page_selected' => true,
    ]);

    $d = app(LocationDashboard::class)->for($location->fresh());

    expect($d['inventory']['towns_covered'])->toBe(2)
        ->and($d['inventory']['towns_selected'])->toBe(2)
        ->and($d['inventory']['population_total'])->toBe(66000)
        ->and($d['inventory']['population_published'])->toBe(36000);   // only Belleville has a live page
});

it('counts indexed pages as the honest union of impressions or a PASS verdict', function () {
    $site = Site::factory()->create();
    $location = ldGbpLocation($site);
    $a = ldClusterPage($site, ['parent_location_id' => $location->id, 'title' => 'A town', 'slug' => 'a-town']);
    $b = ldClusterPage($site, ['parent_location_id' => $location->id, 'title' => 'B town', 'slug' => 'b-town']);

    // A earns impressions; B has a URL-Inspection PASS. Both count as indexed.
    ldGscSnap($site, UrlNormalizer::path(PublicUrl::forContent($site->domain_url, $a) ?? '/a-town'), 'impressions', 10);
    PageIndexState::create([
        'site_id' => $site->id, 'content_id' => $b->id,
        'url' => (string) PublicUrl::forContent($site->domain_url, $b),
        'url_normalized' => UrlNormalizer::url((string) PublicUrl::forContent($site->domain_url, $b)),
        'index_verdict' => 'PASS',
    ]);

    $d = app(LocationDashboard::class)->for($location->fresh());

    expect($d['indexing']['known'])->toBe(2)
        ->and($d['indexing']['indexed'])->toBe(2)
        ->and($d['indexing']['pending'])->toBe(0);
});

it('surfaces location-scoped keyword movement via target_keyword_id', function () {
    $site = Site::factory()->create();
    $location = ldGbpLocation($site);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'plumber newark']);
    ldClusterPage($site, ['location_id' => $location->id, 'title' => 'Newark, NJ', 'target_keyword_id' => $kw->id]);

    PositionSnapshot::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'lane' => 'organic', 'rank' => 8, 'captured_at' => now()->subDays(20)]);
    PositionSnapshot::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'lane' => 'organic', 'rank' => 4, 'captured_at' => now()]);

    $d = app(LocationDashboard::class)->for($location->fresh());

    expect($d['keywords'])->toHaveCount(1)
        ->and($d['keywords'][0]['keyword'])->toBe('plumber newark')
        ->and($d['keywords'][0]['rank'])->toBe(4)
        ->and($d['keywords'][0]['delta'])->toBe(4);   // 8 → 4, improved by 4
});

it('summarizes geo grids for a GBP location and deep-link availability', function () {
    $site = Site::factory()->create();
    $location = ldGbpLocation($site);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'grid kw', 'is_grid_keyword' => true]);
    $scan = GeoGridScan::create([
        'site_id' => $site->id, 'location_id' => $location->id, 'keyword_id' => $kw->id, 'provider' => 'dataforseo',
        'grid_size' => 3, 'spacing_miles' => 1.5, 'center_lat' => 40.7, 'center_lng' => -74.0, 'zoom' => 13,
        'depth_cap' => 20, 'atrp' => 7.5, 'solv' => 40, 'found_rate' => 90, 'status' => 'complete', 'scanned_at' => now(),
    ]);
    foreach (range(0, 8) as $i) {
        GeoGridPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'row' => intdiv($i, 3), 'col' => $i % 3, 'lat' => 40.7, 'lng' => -74.0, 'rank' => 5]);
    }

    $d = app(LocationDashboard::class)->for($location->fresh());

    expect($d['geo_grid']['available'])->toBeTrue()
        ->and($d['geo_grid']['keyword_count'])->toBe(1)
        ->and((float) $d['geo_grid']['worst_atrp'])->toBe(7.5);
});

it('marks reviews unavailable while the provider is the null seam', function () {
    $site = Site::factory()->create();
    $location = ldGbpLocation($site);

    $d = app(LocationDashboard::class)->for($location->fresh());

    expect($d['reviews']['available'])->toBeFalse()
        ->and($d['reviews']['count'])->toBe(0);
});

it('is tenant-isolated — a fresh location on another site sees nothing', function () {
    $siteA = Site::factory()->create();
    $locA = ldGbpLocation($siteA);
    ldClusterPage($siteA, ['location_id' => $locA->id, 'title' => 'A hub']);

    $siteB = Site::factory()->create();
    $locB = ldGbpLocation($siteB, ['name' => 'B loc']);

    $d = app(LocationDashboard::class)->for($locB);

    expect($d['inventory']['pages_total'])->toBe(0)
        ->and($d['keywords'])->toBe([]);
});
