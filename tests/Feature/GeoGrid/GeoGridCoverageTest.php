<?php

use App\GeoGrid\CoverageGrid;
use App\GeoGrid\GeoGridMetrics;
use App\GeoGrid\GeoGridScanner;
use App\Models\CoverageArea;
use App\Models\GeoGridPoint;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('launchpad.geo_grid.poll_max_attempts', 1);
    config()->set('launchpad.geo_grid.poll_interval_seconds', 0);
});

/** Fake the maps standard-queue lifecycle for exactly $count posted tasks, each returning $items. */
function fakeCoverageMaps(int $count, array $items): void
{
    $ids = collect(range(0, max(0, $count - 1)))->map(fn ($i): string => "ctask-{$i}");
    Http::fake([
        '*/serp/google/maps/task_post' => Http::response(['status_code' => 20000, 'tasks' => $ids->map(fn ($id): array => ['id' => $id, 'status_code' => 20000])->all()]),
        '*/serp/google/maps/tasks_ready' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'r', 'status_code' => 20000, 'result' => $ids->map(fn ($id): array => ['id' => $id])->all()]]]),
        '*/serp/google/maps/task_get/advanced/*' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'g', 'status_code' => 20000, 'result' => [['items' => $items]]]]]),
    ]);
}

/** A GBP-backed, grid-ready location (place_id + gbp cid + coords) so the business can be matched. */
function coverageLocation(Site $site, array $extra = []): Location
{
    return Location::factory()->create(array_merge([
        'site_id' => $site->id, 'name' => 'Montclair',
        'gbp_url' => 'https://maps.google/?cid=222', 'place_id' => 'ChIJ_us', 'lat' => 40.81, 'lng' => -74.22,
    ], $extra));
}

function servedTown(Site $site, Location $loc, string $name, int $pop, float $lat, float $lng): CoverageArea
{
    return CoverageArea::factory()->create([
        'site_id' => $site->id, 'name' => $name, 'population' => $pop,
        'lat' => $lat, 'lng' => $lng, 'source_location_ids' => [$loc->id],
    ]);
}

it('resolves a location\'s served, geocoded towns population-descending', function () {
    $site = Site::factory()->create();
    $loc = coverageLocation($site);
    $other = coverageLocation($site, ['name' => 'Other', 'place_id' => 'ChIJ_o']);

    servedTown($site, $loc, 'Nutley', 30000, 40.82, -74.16);
    servedTown($site, $loc, 'Belleville', 36000, 40.79, -74.15);
    CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'NoGeo', 'population' => 99999, 'lat' => null, 'lng' => null, 'source_location_ids' => [$loc->id]]);
    servedTown($site, $other, 'Elsewhere', 50000, 40.5, -74.5);   // another location's town

    $points = app(CoverageGrid::class)->pointsFor($loc->fresh());

    expect($points)->toHaveCount(2)                              // geocoded + served by THIS location only
        ->and($points[0]['label'])->toBe('Belleville')          // higher population first
        ->and($points[0]['population'])->toBe(36000)
        ->and($points[1]['label'])->toBe('Nutley');
});

it('scans a location\'s towns in coverage mode, keyed to coverage_area_id', function () {
    fakeCoverageMaps(2, [
        ['type' => 'maps_search', 'rank_absolute' => 1, 'title' => 'Impostor', 'domain' => 'x.com', 'place_id' => 'ChIJ_other', 'cid' => '999'],
        ['type' => 'maps_search', 'rank_absolute' => 2, 'title' => 'SPG', 'domain' => 'spg.com', 'place_id' => 'ChIJ_us', 'cid' => '222'],
    ]);

    $site = Site::factory()->create();
    $loc = coverageLocation($site);
    servedTown($site, $loc, 'Belleville', 36000, 40.79, -74.15);
    servedTown($site, $loc, 'Nutley', 30000, 40.82, -74.16);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true, 'query' => 'sump pump repair']);

    $scan = app(GeoGridScanner::class)->scanCoverage($loc, $kw);

    expect($scan->mode)->toBe('coverage')
        ->and($scan->grid_size)->toBe(2)                        // point count = town count
        ->and($scan->points()->count())->toBe(2)
        ->and($scan->points()->whereNotNull('coverage_area_id')->count())->toBe(2)
        ->and($scan->points()->pluck('rank')->unique()->all())->toBe([2]);   // matched us by place_id

    $labels = $scan->points()->pluck('label')->all();
    expect($labels)->toContain('Belleville')->toContain('Nutley');
});

it('population-weights coverage metrics by town population', function () {
    $site = Site::factory()->create();
    $loc = coverageLocation($site);
    $big = servedTown($site, $loc, 'Big', 90000, 40.80, -74.20);
    $small = servedTown($site, $loc, 'Small', 10000, 40.70, -74.10);

    $scan = GeoGridScan::create([
        'site_id' => $site->id, 'location_id' => $loc->id, 'keyword_id' => (string) Str::ulid(),
        'provider' => 'dataforseo', 'mode' => 'coverage', 'grid_size' => 2, 'spacing_miles' => 0,
        'center_lat' => 40.75, 'center_lng' => -74.15, 'zoom' => 13, 'depth_cap' => 20, 'status' => 'complete', 'scanned_at' => now(),
    ]);
    GeoGridPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'row' => 0, 'col' => 0, 'coverage_area_id' => $big->id, 'label' => 'Big', 'lat' => 40.80, 'lng' => -74.20, 'rank' => 1]);      // found, top-3
    GeoGridPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'row' => 0, 'col' => 1, 'coverage_area_id' => $small->id, 'label' => 'Small', 'lat' => 40.70, 'lng' => -74.10, 'rank' => null]); // not found

    app(GeoGridMetrics::class)->recompute($scan);
    $scan->refresh();

    expect((float) $scan->found_rate)->toBe(50.0)               // 1 of 2 towns
        ->and((float) $scan->pop_found_rate)->toBe(90.0)        // 90k of 100k population is in a found town
        ->and((float) $scan->pop_solv)->toBe(90.0);             // Big (90k) is top-3
});

it('dry-runs the coverage plan with per-location town counts and spends nothing', function () {
    Http::fake();
    $site = Site::factory()->create();
    $loc = coverageLocation($site);
    servedTown($site, $loc, 'Belleville', 36000, 40.79, -74.15);
    servedTown($site, $loc, 'Nutley', 30000, 40.82, -74.16);
    Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true, 'query' => 'sump pump repair']);

    $this->artisan('launchpad:geo-grid-coverage', ['site' => $site->id, '--dry-run' => true])
        ->expectsOutputToContain('Towns (total across locations)')
        ->expectsOutputToContain('2 town(s) × 1 keyword(s) = 2 requests')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

it('aborts a coverage run that exceeds the hard ceiling', function () {
    config()->set('launchpad.geo_grid.request_ceiling', 1);   // 2 towns × 1 kw = 2 > 1
    Http::fake();
    $site = Site::factory()->create();
    $loc = coverageLocation($site);
    servedTown($site, $loc, 'Belleville', 36000, 40.79, -74.15);
    servedTown($site, $loc, 'Nutley', 30000, 40.82, -74.16);
    Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true, 'query' => 'sump pump repair']);

    $this->artisan('launchpad:geo-grid-coverage', ['site' => $site->id])
        ->expectsOutputToContain('ABORTED')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('runs coverage scans with --force and writes coverage-mode rows', function () {
    fakeCoverageMaps(2, [['type' => 'maps_search', 'rank_absolute' => 1, 'title' => 'SPG', 'domain' => 'spg.com', 'place_id' => 'ChIJ_us', 'cid' => '222']]);
    $site = Site::factory()->create();
    $loc = coverageLocation($site);
    servedTown($site, $loc, 'Belleville', 36000, 40.79, -74.15);
    servedTown($site, $loc, 'Nutley', 30000, 40.82, -74.16);
    Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true, 'query' => 'sump pump repair']);

    $this->artisan('launchpad:geo-grid-coverage', ['site' => $site->id, '--force' => true])
        ->assertExitCode(0);

    expect(GeoGridScan::where('mode', 'coverage')->count())->toBe(1)
        ->and(GeoGridScan::where('mode', 'coverage')->first()->pop_found_rate)->not->toBeNull();
});
