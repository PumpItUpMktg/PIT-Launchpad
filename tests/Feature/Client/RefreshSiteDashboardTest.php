<?php

use App\Analytics\Gsc\Grain;
use App\Enums\BeatabilityLane;
use App\Enums\UserRole;
use App\Filament\Client\Pages\PerformanceOverview;
use App\Jobs\DeriveSiteMilestones;
use App\Jobs\RefreshSiteDashboard;
use App\Jobs\SyncSiteMetrics;
use App\Metrics\MetricProviderRegistry;
use App\Metrics\Milestones\MilestoneDeriver;
use App\Models\ClientMilestone;
use App\Models\Keyword;
use App\Models\MetricSnapshot;
use App\Models\PositionSnapshot;
use App\Models\Site;
use App\Models\User;
use App\Support\CurrentSite;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ClientHarness;

afterEach(function () {
    CurrentSite::clear();
});

it('dispatches a chain of gsc → dataforseo → ga4 → index → milestones (each its own job)', function () {
    Bus::fake();
    $site = Site::factory()->create();

    (new RefreshSiteDashboard($site->id))->handle();

    Bus::assertChained([
        SyncSiteMetrics::class,
        SyncSiteMetrics::class,
        SyncSiteMetrics::class,
        SyncSiteMetrics::class,
        DeriveSiteMilestones::class,
    ]);
});

it('dispatches nothing for a missing site', function () {
    Bus::fake();

    (new RefreshSiteDashboard('01JZZZNOTASITE0000000000'))->handle();

    Bus::assertNothingDispatched();
});

it('the chained steps rebuild the spine and milestones from collected data', function () {
    $site = Site::factory()->create(['domain_url' => 'https://apex.example']);

    // Collected GSC (rolls up to gsc site + page snapshots → first_impression milestone).
    $date = now()->subDays(3)->toDateString();
    $url = 'https://apex.example/sump-pump/';
    DB::table('gsc_url_daily')->insert([
        'id' => (string) Str::ulid(), 'site_id' => $site->id, 'grain_hash' => Grain::hash([$site->id, $date, $url]),
        'date' => $date, 'url' => $url, 'impressions' => 120, 'clicks' => 8, 'ctr' => 0, 'position' => 6,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump repair']);
    PositionSnapshot::factory()->create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'lane' => BeatabilityLane::Organic, 'rank' => 5, 'captured_at' => now()->subDays(2)]);

    // Run the chain's steps the way the queue would (each its own job).
    $registry = app(MetricProviderRegistry::class);
    $window = [now()->subDays(90)->toDateString(), now()->toDateString()];
    (new SyncSiteMetrics($site->id, 'gsc', $window[0], $window[1]))->handle($registry);
    (new SyncSiteMetrics($site->id, 'dataforseo', $window[0], $window[1]))->handle($registry);
    (new SyncSiteMetrics($site->id, 'index', $window[1], $window[1]))->handle($registry);
    (new DeriveSiteMilestones($site->id))->handle(app(MilestoneDeriver::class));

    expect(MetricSnapshot::withoutGlobalScopes()->where('site_id', $site->id)->where('provider', 'gsc')->where('metric_key', 'impressions')->exists())->toBeTrue()
        ->and(MetricSnapshot::withoutGlobalScopes()->where('site_id', $site->id)->where('provider', 'dataforseo')->where('dimension_type', 'keyword')->exists())->toBeTrue()
        ->and(ClientMilestone::withoutGlobalScopes()->where('site_id', $site->id)->where('key', 'first_impression')->exists())->toBeTrue();
});

it('shows the Refresh button to a super-user and dispatches the job', function () {
    Queue::fake();
    config(['launchpad.super_users' => ['boss@example.com']]);
    ['site' => $site] = ClientHarness::make();
    $boss = User::factory()->create(['email' => 'boss@example.com', 'role' => UserRole::Operator]);
    Filament::setCurrentPanel('client');
    $this->actingAs($boss);

    Livewire::test(PerformanceOverview::class)
        ->assertSee('Refresh data')
        ->call('refreshData');

    Queue::assertPushed(RefreshSiteDashboard::class, fn (RefreshSiteDashboard $j): bool => $j->siteId === $site->id);
});

it('hides the Refresh button from a real client', function () {
    Queue::fake();
    config(['launchpad.super_users' => []]);
    ['user' => $client] = ClientHarness::make();
    Filament::setCurrentPanel('client');
    $this->actingAs($client);

    Livewire::test(PerformanceOverview::class)
        ->assertDontSee('Refresh data')
        ->call('refreshData'); // guarded no-op for a non-super-user

    Queue::assertNothingPushed();
});
