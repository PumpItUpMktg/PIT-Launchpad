<?php

use App\Console\Commands\BackfillGscMetricsCommand;
use App\Enums\UserRole;
use App\Jobs\SyncSiteMetrics;
use App\Metrics\Contracts\MetricProvider;
use App\Metrics\MetricProviderRegistry;
use App\Metrics\SyncResult;
use App\Models\ClientMilestone;
use App\Models\MetricSnapshot;
use App\Models\MetricSyncRun;
use App\Models\PageIndexState;
use App\Models\Site;
use App\Models\User;
use App\Support\CurrentSite;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ClientHarness;

afterEach(function () {
    CurrentSite::clear();
});

/** A test-only provider that writes one site-level snapshot row for the range and reports success. */
function fakeGscProvider(int $rows = 1): MetricProvider
{
    return new class($rows) implements MetricProvider
    {
        public function __construct(private int $rows) {}

        public function key(): string
        {
            return 'gsc';
        }

        public function sync(Site $site, CarbonPeriod $range): SyncResult
        {
            MetricSnapshot::withoutGlobalScopes()->updateOrCreate(
                [
                    'site_id' => $site->id, 'provider' => 'gsc', 'metric_key' => 'impressions',
                    'dimension_type' => 'site', 'dimension_value' => '', 'period_grain' => 'day',
                    'period_date' => $range->getStartDate()->toDateString(),
                ],
                ['value_numeric' => 42, 'captured_at' => now()],
            );

            return SyncResult::success($this->rows);
        }
    };
}

it('upserts idempotently on the grain unique key', function () {
    $site = Site::factory()->create();
    CurrentSite::set($site->id);

    $grain = [
        'provider' => 'gsc', 'metric_key' => 'clicks', 'dimension_type' => 'page',
        'dimension_value' => '/sump-pump-repair', 'period_grain' => 'day', 'period_date' => Carbon::parse('2026-08-01'),
    ];

    MetricSnapshot::updateOrCreate($grain, ['value_numeric' => 10, 'captured_at' => now()]);
    MetricSnapshot::updateOrCreate($grain, ['value_numeric' => 25, 'captured_at' => now()]);

    expect(MetricSnapshot::count())->toBe(1)
        ->and((int) MetricSnapshot::first()->value_numeric)->toBe(25);
});

it('scopes snapshots, index states and milestones to the tenant', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();

    $bSnap = MetricSnapshot::withoutGlobalScopes()->create([
        'site_id' => $b->id, 'provider' => 'gsc', 'metric_key' => 'impressions', 'dimension_type' => 'site',
        'dimension_value' => '', 'period_grain' => 'day', 'period_date' => '2026-08-01', 'value_numeric' => 5, 'captured_at' => now(),
    ]);
    $bIndex = PageIndexState::withoutGlobalScopes()->create([
        'site_id' => $b->id, 'url' => 'https://b.example/x', 'url_normalized' => 'https://b.example/x', 'index_verdict' => 'PASS',
    ]);
    $bMilestone = ClientMilestone::withoutGlobalScopes()->create([
        'site_id' => $b->id, 'key' => 'first_page_indexed', 'occurred_on' => '2026-08-01',
    ]);

    CurrentSite::set($a->id);

    expect(MetricSnapshot::find($bSnap->id))->toBeNull()
        ->and(PageIndexState::find($bIndex->id))->toBeNull()
        ->and(ClientMilestone::find($bMilestone->id))->toBeNull()
        ->and(MetricSnapshot::count())->toBe(0);

    // The rows exist — only the tenant boundary hides them.
    expect(MetricSnapshot::withoutGlobalScopes()->find($bSnap->id))->not->toBeNull();
});

it('records a failed sync run when the provider is not registered', function () {
    $site = Site::factory()->create();

    (new SyncSiteMetrics($site->id, 'gsc', '2026-08-01', '2026-08-31'))
        ->handle(app(MetricProviderRegistry::class));

    $run = MetricSyncRun::withoutGlobalScopes()->first();
    expect($run)->not->toBeNull()
        ->and($run->status)->toBe(MetricSyncRun::STATUS_FAILED)
        ->and($run->error_message)->toContain('not registered');
});

it('runs a registered provider and records a successful run', function () {
    $site = Site::factory()->create();
    app(MetricProviderRegistry::class)->register(fakeGscProvider(rows: 3));

    (new SyncSiteMetrics($site->id, 'gsc', '2026-08-01', '2026-08-01'))
        ->handle(app(MetricProviderRegistry::class));

    $run = MetricSyncRun::withoutGlobalScopes()->first();
    expect($run->status)->toBe(MetricSyncRun::STATUS_SUCCESS)
        ->and($run->rows_written)->toBe(3)
        ->and($run->finished_at)->not->toBeNull()
        ->and(MetricSnapshot::withoutGlobalScopes()->where('site_id', $site->id)->count())->toBe(1);
});

it('dispatches each provider sync onto its own queue', function () {
    Queue::fake();
    $site = Site::factory()->create();

    SyncSiteMetrics::dispatch($site->id, 'gsc', '2026-08-01', '2026-08-31');

    Queue::assertPushed(SyncSiteMetrics::class, fn (SyncSiteMetrics $j): bool => $j->queue === 'metrics:gsc' && $j->provider === 'gsc');
});

it('builds oldest-first month-chunks clamped to today at the tail', function () {
    $chunks = BackfillGscMetricsCommand::monthChunks(Carbon::parse('2026-08-22'), 3);

    expect($chunks)->toHaveCount(3)
        ->and($chunks[0][0]->toDateString())->toBe('2026-06-01')
        ->and($chunks[0][1]->toDateString())->toBe('2026-06-30')
        ->and($chunks[2][0]->toDateString())->toBe('2026-08-01')
        ->and($chunks[2][1]->toDateString())->toBe('2026-08-22'); // clamped to "now", not 08-31
});

it('backfill does nothing while the gsc provider is unregistered', function () {
    Queue::fake();
    $site = Site::factory()->create();

    $this->artisan('sandhog:backfill-gsc', ['site' => $site->id, '--months' => 3])
        ->expectsOutputToContain('not registered yet')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

it('backfill fans out one job per month-chunk once the provider is registered', function () {
    Queue::fake();
    $site = Site::factory()->create();
    app(MetricProviderRegistry::class)->register(fakeGscProvider());

    $this->artisan('sandhog:backfill-gsc', ['site' => $site->id, '--months' => 4])->assertSuccessful();

    Queue::assertPushed(SyncSiteMetrics::class, 4);
});

it('backfill --resume skips month-chunks a successful run already covers', function () {
    Queue::fake();
    $site = Site::factory()->create();
    app(MetricProviderRegistry::class)->register(fakeGscProvider());

    // One chunk (the oldest of a 3-month walk from a first-of-month "now") already succeeded.
    $chunks = BackfillGscMetricsCommand::monthChunks(Carbon::now(), 3);
    MetricSyncRun::withoutGlobalScopes()->create([
        'site_id' => $site->id, 'provider' => 'gsc', 'status' => MetricSyncRun::STATUS_SUCCESS,
        'range_start' => $chunks[0][0]->toDateString(), 'range_end' => $chunks[0][1]->toDateString(),
        'finished_at' => now(),
    ]);

    $this->artisan('sandhog:backfill-gsc', ['site' => $site->id, '--months' => 3, '--resume' => true])->assertSuccessful();

    Queue::assertPushed(SyncSiteMetrics::class, 2); // 3 chunks − 1 already covered
});

it('lets a client read their own site metrics but not another tenant\'s', function () {
    ['user' => $client, 'site' => $site] = ClientHarness::make();
    ['site' => $otherSite] = ClientHarness::make();

    $mine = MetricSnapshot::withoutGlobalScopes()->create([
        'site_id' => $site->id, 'provider' => 'gsc', 'metric_key' => 'impressions', 'dimension_type' => 'site',
        'dimension_value' => '', 'period_grain' => 'day', 'period_date' => '2026-08-01', 'value_numeric' => 1, 'captured_at' => now(),
    ]);
    $theirs = MetricSnapshot::withoutGlobalScopes()->create([
        'site_id' => $otherSite->id, 'provider' => 'gsc', 'metric_key' => 'impressions', 'dimension_type' => 'site',
        'dimension_value' => '', 'period_grain' => 'day', 'period_date' => '2026-08-01', 'value_numeric' => 1, 'captured_at' => now(),
    ]);

    expect($client->can('view', $mine))->toBeTrue()
        ->and($client->can('view', $theirs))->toBeFalse();
});

it('keeps sync-run records operator-only', function () {
    $operator = User::factory()->create(['role' => UserRole::Operator]);
    ['user' => $client, 'site' => $site] = ClientHarness::make();

    $run = MetricSyncRun::withoutGlobalScopes()->create([
        'site_id' => $site->id, 'provider' => 'gsc', 'status' => MetricSyncRun::STATUS_SUCCESS,
    ]);

    expect($operator->can('view', $run))->toBeTrue()
        ->and($client->can('view', $run))->toBeFalse();
});
