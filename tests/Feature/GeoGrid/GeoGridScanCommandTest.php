<?php

use App\Models\GeoGridScan;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use Illuminate\Support\Facades\Http;

function gridReadyLocation(Site $site, array $extra = []): Location
{
    return Location::factory()->create(array_merge([
        'site_id' => $site->id,
        'gbp_url' => 'https://maps.google/?cid=222', 'place_id' => 'ChIJ_us',
        'lat' => 40.7128, 'lng' => -74.0060,
    ], $extra));
}

it('dry-run reports the plan and spends nothing', function () {
    Http::fake();   // any call would be a failure of intent
    $site = Site::factory()->create();
    gridReadyLocation($site);
    Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true, 'query' => 'sump pump repair']);

    $this->artisan('launchpad:geo-grid-scan', ['site' => $site->id, '--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);

    Http::assertNothingSent();
    expect(GeoGridScan::count())->toBe(0);
});

it('shows the --radius override converted to spacing in the dry-run plan', function () {
    config(['launchpad.geo_grid.grid_size' => 7]);
    Http::fake();
    $site = Site::factory()->create();
    gridReadyLocation($site, ['name' => 'Montclair']);
    Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true, 'query' => 'sump pump repair']);

    $this->artisan('launchpad:geo-grid-scan', ['site' => $site->id, '--radius' => 10, '--dry-run' => true])
        ->expectsOutputToContain('radius 10 mi → spacing 3.33 mi')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

it('aborts a live run that exceeds the hard request ceiling', function () {
    Http::fake();
    config()->set('launchpad.geo_grid.request_ceiling', 50);   // 2 locations × 1 kw × 49 = 98 > 50
    $site = Site::factory()->create();
    gridReadyLocation($site);
    gridReadyLocation($site, ['place_id' => 'ChIJ_us2']);
    Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true, 'query' => 'sump pump repair']);

    $this->artisan('launchpad:geo-grid-scan', ['site' => $site->id])
        ->expectsOutputToContain('ABORTED')
        ->assertExitCode(1);

    Http::assertNothingSent();
});

it('says nothing to scan when there are no grid keywords', function () {
    Http::fake();
    $site = Site::factory()->create();
    gridReadyLocation($site);

    $this->artisan('launchpad:geo-grid-scan', ['site' => $site->id])
        ->expectsOutputToContain('Nothing to scan')
        ->assertExitCode(0);
});

it('runs a confirmed scan and writes a scan row', function () {
    config()->set('launchpad.geo_grid.grid_size', 3);
    config()->set('launchpad.geo_grid.poll_max_attempts', 1);
    config()->set('launchpad.geo_grid.poll_interval_seconds', 0);
    $ids = collect(range(0, 8))->map(fn ($i): string => "task-{$i}");
    Http::fake([
        '*/serp/google/maps/task_post' => Http::response(['status_code' => 20000, 'tasks' => $ids->map(fn ($id): array => ['id' => $id, 'status_code' => 20000])->all()]),
        '*/serp/google/maps/tasks_ready' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'r', 'status_code' => 20000, 'result' => $ids->map(fn ($id): array => ['id' => $id])->all()]]]),
        '*/serp/google/maps/task_get/advanced/*' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'g', 'status_code' => 20000, 'result' => [['items' => [
            ['type' => 'maps_search', 'rank_absolute' => 2, 'title' => 'Us', 'domain' => 'us.com', 'place_id' => 'ChIJ_us', 'cid' => '222'],
        ]]]]]]),
    ]);

    $site = Site::factory()->create();
    gridReadyLocation($site);
    Keyword::factory()->create(['site_id' => $site->id, 'is_grid_keyword' => true, 'query' => 'sump pump repair']);

    $this->artisan('launchpad:geo-grid-scan', ['site' => $site->id])
        ->expectsConfirmation('Run 1 scan(s) = 9 DataForSEO requests (~$0.02)?', 'yes')
        ->assertExitCode(0);

    expect(GeoGridScan::count())->toBe(1)
        ->and(GeoGridScan::first()->points()->where('rank', 2)->count())->toBe(9);
});
