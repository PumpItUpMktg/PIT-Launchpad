<?php

use App\Enums\ScanCadence;
use App\GeoGrid\GeoGridMetrics;
use App\GeoGrid\GeoGridScanner;
use App\Jobs\RunCoverageScan;
use App\Models\CoverageArea;
use App\Models\CoverageScanPlan;
use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function crdLoc(Site $site): Location
{
    return Location::factory()->create([
        'site_id' => $site->id, 'name' => 'Montclair',
        'gbp_url' => 'https://maps.google/?cid=222', 'place_id' => 'ChIJ_us', 'lat' => 40.81, 'lng' => -74.22,
    ]);
}

function crdTowns(Site $site, Location $loc, int $n): void
{
    for ($i = 0; $i < $n; $i++) {
        CoverageArea::factory()->create(['site_id' => $site->id, 'name' => "T{$i}", 'population' => 9000, 'lat' => 40.8 + $i * 0.01, 'lng' => -74.2, 'source_location_ids' => [$loc->id]]);
    }
}

/** A due plan (next_run in the past). */
function duePlan(Site $site, Location $loc, array $keywordIds): CoverageScanPlan
{
    $plan = CoverageScanPlan::create(['site_id' => $site->id, 'location_id' => $loc->id, 'cadence' => ScanCadence::Monthly, 'enabled' => true, 'keyword_ids' => $keywordIds]);
    $plan->forceFill(['next_run_at' => now()->subDay()])->save();   // hook preserves a non-null past value

    return $plan->fresh();
}

it('dispatches due plans, advances next_run, and stamps last_run', function () {
    Queue::fake();
    $site = Site::factory()->create();
    $loc = crdLoc($site);
    crdTowns($site, $loc, 3);
    $k1 = Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true]);
    $k2 = Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true]);
    $plan = duePlan($site, $loc, [$k1->id, $k2->id]);

    $this->artisan('launchpad:run-due-coverage-plans')->assertExitCode(0);

    Queue::assertPushed(RunCoverageScan::class, 2);      // one per keyword
    $plan->refresh();
    expect($plan->next_run_at->isFuture())->toBeTrue()
        ->and($plan->last_run_at)->not->toBeNull();
});

it('skips a plan whose run would exceed the ceiling and leaves it due', function () {
    config()->set('launchpad.geo_grid.request_ceiling', 2);   // 3 towns × 1 kw = 3 > 2
    Queue::fake();
    $site = Site::factory()->create();
    $loc = crdLoc($site);
    crdTowns($site, $loc, 3);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true]);
    $plan = duePlan($site, $loc, [$kw->id]);

    $this->artisan('launchpad:run-due-coverage-plans')->expectsOutputToContain('Skipped')->assertExitCode(0);

    Queue::assertNothingPushed();
    expect($plan->fresh()->next_run_at->isPast())->toBeTrue();   // still due — visibly stuck, not silently dropped
});

it('does not run a plan that is not yet due', function () {
    Queue::fake();
    $site = Site::factory()->create();
    $loc = crdLoc($site);
    crdTowns($site, $loc, 2);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true]);
    CoverageScanPlan::create(['site_id' => $site->id, 'location_id' => $loc->id, 'cadence' => ScanCadence::Monthly, 'enabled' => true, 'keyword_ids' => [$kw->id]])
        ->forceFill(['next_run_at' => now()->addWeek()])->save();

    $this->artisan('launchpad:run-due-coverage-plans')->expectsOutputToContain('No coverage plans due')->assertExitCode(0);
    Queue::assertNothingPushed();
});

it('dry-run dispatches nothing and does not advance', function () {
    Queue::fake();
    $site = Site::factory()->create();
    $loc = crdLoc($site);
    crdTowns($site, $loc, 2);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true]);
    $plan = duePlan($site, $loc, [$kw->id]);

    $this->artisan('launchpad:run-due-coverage-plans', ['--dry-run' => true])->assertExitCode(0);

    Queue::assertNothingPushed();
    expect($plan->fresh()->next_run_at->isPast())->toBeTrue();
});

it('the RunCoverageScan job scans the towns and writes a coverage scan', function () {
    config()->set('launchpad.geo_grid.poll_max_attempts', 1);
    config()->set('launchpad.geo_grid.poll_interval_seconds', 0);
    $ids = collect(range(0, 1))->map(fn ($i): string => "j-{$i}");
    Http::fake([
        '*/serp/google/maps/task_post' => Http::response(['status_code' => 20000, 'tasks' => $ids->map(fn ($id): array => ['id' => $id, 'status_code' => 20000])->all()]),
        '*/serp/google/maps/tasks_ready' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'r', 'status_code' => 20000, 'result' => $ids->map(fn ($id): array => ['id' => $id])->all()]]]),
        '*/serp/google/maps/task_get/advanced/*' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'g', 'status_code' => 20000, 'result' => [['items' => [['type' => 'maps_search', 'rank_absolute' => 1, 'title' => 'SPG', 'domain' => 'spg.com', 'place_id' => 'ChIJ_us', 'cid' => '222']]]]]]]),
    ]);

    $site = Site::factory()->create();
    $loc = crdLoc($site);
    crdTowns($site, $loc, 2);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true, 'query' => 'sump pump repair']);

    (new RunCoverageScan($loc->id, $kw->id))->handle(app(GeoGridScanner::class), app(GeoGridMetrics::class));

    $scan = GeoGridScan::where('mode', 'coverage')->first();
    expect($scan)->not->toBeNull()->and($scan->grid_size)->toBe(2);

    // Null-guard: unknown ids do nothing, don't throw.
    (new RunCoverageScan((string) Str::ulid(), (string) Str::ulid()))->handle(app(GeoGridScanner::class), app(GeoGridMetrics::class));
    expect(GeoGridScan::where('mode', 'coverage')->count())->toBe(1);
});
