<?php

use App\Jobs\IngestTownRankScans;
use App\Jobs\RunTownRankSweep;
use App\Models\CoverageArea;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use App\TownRank\TownRankSweep;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function sweepSite(int $towns = 2): array
{
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.com', 'status' => \App\Enums\SiteStatus::Live]);
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.0, 'lng' => -74.0]);
    for ($i = 0; $i < $towns; $i++) {
        CoverageArea::factory()->create(['site_id' => $site->id, 'name' => "Town {$i}", 'population' => 100, 'lat' => 40.0 + $i / 100, 'lng' => -74.0, 'source_location_ids' => [$loc->id]]);
    }
    $grid = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service', 'is_grid_keyword' => true]);
    $scanned = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump repair', 'is_grid_keyword' => false]);
    Keyword::factory()->create(['site_id' => $site->id, 'query' => 'unrelated', 'is_grid_keyword' => false]);   // never scanned, not grid → not in the set

    return [$site, $grid, $scanned];
}

function sweepFakeQueue(int $count): void
{
    $ids = collect(range(0, max(0, $count - 1)))->map(fn ($i): string => "stask-{$i}");
    Http::fake([
        '*/serp/google/organic/task_post' => Http::response(['status_code' => 20000, 'tasks' => $ids->map(fn ($id): array => ['id' => $id, 'status_code' => 20000])->all()]),
        '*/serp/google/organic/tasks_ready' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'r', 'status_code' => 20000, 'result' => $ids->map(fn ($id): array => ['id' => $id])->all()]]]),
        '*/serp/google/organic/task_get/advanced/*' => Http::response(['status_code' => 20000, 'tasks' => [['id' => 'g', 'status_code' => 20000, 'result' => [['items' => [['type' => 'organic', 'rank_absolute' => 2, 'url' => 'https://spg.com/x', 'domain' => 'spg.com']]]]]]]),
    ]);
}

it('a pair is due when never scanned or past the cadence; fresh and pending scans are not re-posted', function () {
    config()->set('launchpad.town_rank.cadence_days', 7);
    [$site, $grid, $scanned] = sweepSite();
    // "repair" is in the set only because it was scanned before: local fresh (not due), town_query old (due).
    TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $scanned->id, 'mode' => 'local', 'status' => 'complete', 'scanned_at' => now()->subDays(2)]);
    TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $scanned->id, 'mode' => 'town_query', 'status' => 'complete', 'scanned_at' => now()->subDays(9)]);
    // grid keyword: local pending (not due), town_query never scanned (due).
    TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $grid->id, 'mode' => 'local', 'status' => 'pending', 'scanned_at' => now()->subDays(20)]);

    $due = app(TownRankSweep::class)->due($site);

    expect(array_map(fn (array $p): string => $p['keyword']->query.'·'.$p['mode'], $due))
        ->toBe(['sump pump repair·town_query', 'sump pump service·town_query']);
});

it('plans requests = towns × due pairs, posts when under the ceiling, and skips whole when over it', function () {
    sweepFakeQueue(2);
    [$site] = sweepSite(2);   // grid keyword never scanned → 2 pairs due → 4 requests

    config()->set('launchpad.town_rank.request_ceiling', 3);
    $plan = app(TownRankSweep::class)->plan($site);
    expect($plan['requests'])->toBe(4)->and($plan['over_ceiling'])->toBeTrue();
    expect(app(TownRankSweep::class)->run($site))->toMatchArray(['posted' => 0, 'over_ceiling' => true]);
    Http::assertNothingSent();

    config()->set('launchpad.town_rank.request_ceiling', 100);
    expect(app(TownRankSweep::class)->run($site))->toMatchArray(['posted' => 2, 'over_ceiling' => false]);
    expect(TownRankScan::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('status', 'pending')->count())->toBe(2);

    // Nothing is due right after posting (both pairs are pending).
    expect(app(TownRankSweep::class)->due($site))->toBe([]);
});

it('the sweep command plans per eligible site and dispatches one job per site unless --dry-run', function () {
    Queue::fake();
    Http::fake();
    [$site] = sweepSite(2);
    Site::factory()->create(['brand_name' => 'Onboarding Co', 'status' => \App\Enums\SiteStatus::Onboarding]);   // not eligible

    $this->artisan('launchpad:town-rank-sweep', ['--dry-run' => true])
        ->expectsOutputToContain('SPG — 2 town(s) × 2 due pair(s) = 4 request(s)')
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);
    Queue::assertNothingPushed();

    $this->artisan('launchpad:town-rank-sweep')
        ->expectsOutputToContain('Dispatched 1 site sweep(s)')
        ->assertExitCode(0);
    Queue::assertPushed(RunTownRankSweep::class, fn (RunTownRankSweep $j): bool => $j->siteId === (string) $site->id);
    Queue::assertPushed(RunTownRankSweep::class, 1);
});

it('the ingest sweep collects pending scans within its budget and closes expired ones as partial', function () {
    sweepFakeQueue(2);
    [$site, $grid] = sweepSite(2);
    config()->set('launchpad.town_rank.request_ceiling', 100);
    app(TownRankSweep::class)->run($site);   // 2 pending scans, 2 towns each
    // A stale pending scan with a task that never became ready.
    $stale = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $grid->id, 'mode' => 'local', 'status' => 'pending', 'points_count' => 1, 'scanned_at' => now()->subHours(30)]);
    TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $stale->id, 'label' => 'Old', 'lat' => 40.0, 'lng' => -74.0, 'query' => 'q', 'provider_task_id' => 'never-ready', 'collected_at' => null]);

    config()->set('launchpad.town_rank.ingest_batch', 3);   // 4 points ready + 1 never: budget stops at 3
    (new IngestTownRankScans)->handle(app(\App\TownRank\TownRankScanner::class));

    expect(TownRankPoint::query()->withoutGlobalScopes()->whereNotNull('collected_at')->count())->toBe(3)
        ->and($stale->fresh()->status)->toBe('partial');   // expired → closed over what it has

    config()->set('launchpad.town_rank.ingest_batch', 40);
    (new IngestTownRankScans)->handle(app(\App\TownRank\TownRankScanner::class));
    expect(TownRankScan::query()->withoutGlobalScopes()->where('site_id', $site->id)->where('status', 'complete')->count())->toBe(2);
});
