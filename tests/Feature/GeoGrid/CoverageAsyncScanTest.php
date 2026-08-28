<?php

use App\GeoGrid\CoverageMap;
use App\GeoGrid\GeoGridMetrics;
use App\GeoGrid\GeoGridScanner;
use App\Jobs\IngestCoverageScans;
use App\Jobs\RunCoverageScan;
use App\Models\CoverageArea;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use Illuminate\Support\Facades\Http;

/** A GBP-backed, grid-ready location the business can be matched at. */
function asyncLoc(Site $site): Location
{
    return Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Downingtown',
        'gbp_url' => 'https://maps.google/?cid=222', 'place_id' => 'ChIJ_us', 'lat' => 40.0, 'lng' => -75.7,
    ]);
}

function asyncTown(Site $site, Location $loc, string $name, int $pop, float $lat, float $lng): CoverageArea
{
    return CoverageArea::factory()->create([
        'site_id' => $site->id, 'name' => $name, 'population' => $pop,
        'lat' => $lat, 'lng' => $lng, 'source_location_ids' => [$loc->id],
    ]);
}

/** task_post → $count ids (ctask-0..). tasks_ready → only $readyCount of them. task_get → $items. */
function asyncFakeMaps(int $count, int $readyCount, array $items): void
{
    $ids = collect(range(0, max(0, $count - 1)))->map(fn ($i): string => "ctask-{$i}");
    $ready = $ids->take($readyCount);
    Http::fake([
        '*/serp/google/maps/task_post' => Http::response(['status_code' => 20000, 'tasks' => $ids->map(fn ($id): array => ['id' => $id, 'status_code' => 20000])->all()]),
        '*/serp/google/maps/tasks_ready' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'r', 'status_code' => 20000, 'result' => $ready->map(fn ($id): array => ['id' => $id])->all()]]]),
        '*/serp/google/maps/task_get/advanced/*' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'g', 'status_code' => 20000, 'result' => [['items' => $items]]]]]),
    ]);
}

/** The two match-items: an impostor at #1 (wrong place_id) and us at #2. */
function asyncItems(): array
{
    return [
        ['type' => 'maps_search', 'rank_absolute' => 1, 'title' => 'Impostor', 'domain' => 'x.com', 'place_id' => 'ChIJ_other', 'cid' => '999'],
        ['type' => 'maps_search', 'rank_absolute' => 2, 'title' => 'SPG', 'domain' => 'spg.com', 'place_id' => 'ChIJ_us', 'cid' => '222'],
    ];
}

it('RunCoverageScan only POSTS — a pending scan, points with task ids, no ranks or aggregates yet', function () {
    asyncFakeMaps(2, 0, asyncItems());
    $site = Site::factory()->create();
    $loc = asyncLoc($site);
    asyncTown($site, $loc, 'Coatesville', 13000, 39.98, -75.82);
    asyncTown($site, $loc, 'Exton', 5000, 40.03, -75.62);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true, 'query' => 'sump pump repair']);

    (new RunCoverageScan($loc->id, $kw->id))->handle(app(GeoGridScanner::class));

    $scan = GeoGridScan::where('mode', 'coverage')->firstOrFail();
    expect($scan->status)->toBe('pending')
        ->and($scan->points()->count())->toBe(2)
        ->and($scan->points()->whereNotNull('provider_task_id')->count())->toBe(2)
        ->and($scan->points()->whereNull('collected_at')->count())->toBe(2)
        ->and($scan->points()->whereNotNull('rank')->count())->toBe(0)
        ->and($scan->found_rate)->toBeNull();   // aggregates wait for collection
});

it('the IngestCoverageScans sweep collects ready tasks, finalizes complete, and recomputes aggregates', function () {
    asyncFakeMaps(2, 2, asyncItems());
    $site = Site::factory()->create();
    $loc = asyncLoc($site);
    asyncTown($site, $loc, 'Coatesville', 13000, 39.98, -75.82);
    asyncTown($site, $loc, 'Exton', 5000, 40.03, -75.62);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true, 'query' => 'sump pump repair']);

    (new RunCoverageScan($loc->id, $kw->id))->handle(app(GeoGridScanner::class));
    (new IngestCoverageScans)->handle(app(GeoGridScanner::class), app(GeoGridMetrics::class));

    $scan = GeoGridScan::where('mode', 'coverage')->firstOrFail();
    expect($scan->status)->toBe('complete')
        ->and($scan->points()->whereNull('collected_at')->count())->toBe(0)
        ->and($scan->points()->pluck('rank')->unique()->all())->toBe([2])   // matched us by place_id
        ->and((float) $scan->found_rate)->toBe(100.0);                      // both towns found → aggregates filled
});

it('collects within the per-run batch budget, finishing the rest on the next sweep', function () {
    config()->set('launchpad.geo_grid.ingest_batch', 1);   // one task_get per sweep run
    asyncFakeMaps(2, 2, asyncItems());
    $site = Site::factory()->create();
    $loc = asyncLoc($site);
    asyncTown($site, $loc, 'Coatesville', 13000, 39.98, -75.82);
    asyncTown($site, $loc, 'Exton', 5000, 40.03, -75.62);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true, 'query' => 'sump pump repair']);

    (new RunCoverageScan($loc->id, $kw->id))->handle(app(GeoGridScanner::class));

    (new IngestCoverageScans)->handle(app(GeoGridScanner::class), app(GeoGridMetrics::class));
    $scan = GeoGridScan::where('mode', 'coverage')->firstOrFail();
    expect($scan->status)->toBe('pending')                                  // one of two collected — still pending
        ->and($scan->points()->whereNotNull('collected_at')->count())->toBe(1);

    (new IngestCoverageScans)->handle(app(GeoGridScanner::class), app(GeoGridMetrics::class));
    expect($scan->refresh()->status)->toBe('complete')
        ->and($scan->points()->whereNotNull('collected_at')->count())->toBe(2);
});

it('finalizes a stuck pending scan as partial past the expiry window', function () {
    config()->set('launchpad.geo_grid.pending_expiry_hours', 24);
    asyncFakeMaps(2, 0, asyncItems());   // no task ever becomes ready
    $site = Site::factory()->create();
    $loc = asyncLoc($site);
    asyncTown($site, $loc, 'Coatesville', 13000, 39.98, -75.82);
    asyncTown($site, $loc, 'Exton', 5000, 40.03, -75.62);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true, 'query' => 'sump pump repair']);

    (new RunCoverageScan($loc->id, $kw->id))->handle(app(GeoGridScanner::class));
    GeoGridScan::where('mode', 'coverage')->update(['scanned_at' => now()->subHours(25)]);

    (new IngestCoverageScans)->handle(app(GeoGridScanner::class), app(GeoGridMetrics::class));

    $scan = GeoGridScan::where('mode', 'coverage')->firstOrFail();
    expect($scan->status)->toBe('partial')
        ->and((float) $scan->found_rate)->toBe(0.0);   // recomputed over what it has (nothing found)
});

it('hides a still-pending scan from the coverage read-model, surfacing it once collected', function () {
    // Ready from the start; RunCoverageScan is post-only (never polls), so the scan stays pending until the sweep.
    asyncFakeMaps(2, 2, asyncItems());
    $site = Site::factory()->create();
    $loc = asyncLoc($site);
    asyncTown($site, $loc, 'Coatesville', 13000, 39.98, -75.82);
    asyncTown($site, $loc, 'Exton', 5000, 40.03, -75.62);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true, 'query' => 'sump pump repair']);

    (new RunCoverageScan($loc->id, $kw->id))->handle(app(GeoGridScanner::class));

    // Pending → the card shows nothing yet (no all-zero flash).
    expect(app(CoverageMap::class)->areaScore($loc->fresh()))->toBeNull()
        ->and(app(CoverageMap::class)->for($loc->fresh())['services'])->toBe([]);

    // Collect → it now surfaces.
    (new IngestCoverageScans)->handle(app(GeoGridScanner::class), app(GeoGridMetrics::class));
    expect(GeoGridScan::where('mode', 'coverage')->firstOrFail()->status)->toBe('complete')
        ->and(app(CoverageMap::class)->areaScore($loc->fresh()))->not->toBeNull();
});
