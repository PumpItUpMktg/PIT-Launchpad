<?php

use App\Enums\UserRole;
use App\Filament\Pages\Operate\TenantDashboard;
use App\Models\Keyword;
use App\Models\PageIndexState;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use App\Operator\SiteDashboard;
use App\Support\CurrentSite;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

afterEach(function () {
    CurrentSite::clear();
});

/** Seed a metric-spine row (day grain). */
function tdSnap(Site $site, string $provider, string $metric, string $dimType, string $dimValue, string $date, float $value): void
{
    DB::table('metric_snapshots')->insert([
        'id' => (string) Str::ulid(), 'site_id' => $site->id, 'provider' => $provider, 'metric_key' => $metric,
        'dimension_type' => $dimType, 'dimension_value' => $dimValue, 'period_grain' => 'day', 'period_date' => $date,
        'value_numeric' => $value, 'value_json' => null,
        'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function tdVital(Site $site, ?int $score, ?int $lcp, ?float $cls, ?int $inp): void
{
    DB::table('page_vitals')->insert([
        'id' => (string) Str::ulid(), 'site_id' => $site->id, 'content_id' => null,
        'url' => 'https://x.test/'.Str::random(6), 'url_normalized' => '/'.Str::random(6), 'strategy' => 'mobile',
        'performance_score' => $score, 'lcp_ms' => $lcp, 'cls' => $cls, 'inp_ms' => $inp,
        'measured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** A full spine for one site, all dated today so the trailing window catches the summables. */
function tdSeedSite(Site $site): void
{
    $today = now()->toDateString();
    // GSC site totals + per-page (for weighted position).
    tdSnap($site, 'gsc', 'impressions', 'site', '', $today, 900);
    tdSnap($site, 'gsc', 'clicks', 'site', '', $today, 45);
    tdSnap($site, 'gsc', 'impressions', 'page', '/a', $today, 600);
    tdSnap($site, 'gsc', 'position', 'page', '/a', $today, 4.0);
    tdSnap($site, 'gsc', 'impressions', 'page', '/b', $today, 300);
    tdSnap($site, 'gsc', 'position', 'page', '/b', $today, 10.0);
    // GA4.
    tdSnap($site, 'ga4', 'sessions', 'site', '', $today, 120);
    // Index standings.
    tdSnap($site, 'index', 'pages_indexed', 'site', '', $today, 18);
    tdSnap($site, 'index', 'pages_known', 'site', '', $today, 25);
    // Rankings standings.
    tdSnap($site, 'dataforseo', 'keywords_ranked', 'site', '', $today, 30);
    tdSnap($site, 'dataforseo', 'keywords_top3', 'site', '', $today, 5);
    tdSnap($site, 'dataforseo', 'keywords_top10', 'site', '', $today, 12);
    tdSnap($site, 'dataforseo', 'keywords_top20', 'site', '', $today, 20);
    // Speed.
    tdVital($site, 90, 2000, 0.05, 150); // passes CWV
    tdVital($site, 70, 3200, 0.2, 300);  // fails CWV
    // Not-indexed URLs with reasons.
    PageIndexState::create(['site_id' => $site->id, 'url' => 'https://x.test/1', 'url_normalized' => '/1', 'index_verdict' => 'NEUTRAL', 'coverage_state' => 'crawled_not_indexed']);
    PageIndexState::create(['site_id' => $site->id, 'url' => 'https://x.test/2', 'url_normalized' => '/2', 'index_verdict' => 'NEUTRAL', 'coverage_state' => 'excluded_canonical']);
    PageIndexState::create(['site_id' => $site->id, 'url' => 'https://x.test/3', 'url_normalized' => '/3', 'index_verdict' => 'PASS', 'coverage_state' => 'indexed']);
    // Keywords.
    Keyword::factory()->count(30)->create(['site_id' => $site->id]);
}

it('reads every metric card from persisted state', function () {
    $site = Site::factory()->create();
    tdSeedSite($site);

    $m = app(SiteDashboard::class)->metrics($site);

    // PageSpeed: median of [70, 90] = 80; 1 of 2 pass CWV.
    expect($m['pagespeed']['value'])->toBe(80)
        ->and($m['pagespeed']['cwv_pass'])->toBe(1)
        ->and($m['pagespeed']['measured'])->toBe(2);

    // GSC + GA4 window sums.
    expect($m['impressions']['value'])->toBe(900)
        ->and($m['clicks']['value'])->toBe(45)
        ->and($m['sessions']['value'])->toBe(120);

    // Impression-weighted avg position: (4·600 + 10·300) / 900 = 6.0.
    expect($m['avg_position']['value'])->toBe(6.0);

    // Index standings.
    expect($m['indexed']['value'])->toBe(18)->and($m['indexed']['known'])->toBe(25);

    // Not-indexed: the two non-PASS rows, grouped by reason.
    expect($m['not_indexed']['value'])->toBe(2)
        ->and($m['not_indexed']['reasons'])->toHaveCount(2);

    // Keywords + rankings.
    expect($m['keywords']['value'])->toBe(30)
        ->and($m['rankings']['value'])->toBe(30)
        ->and($m['rankings']['top3'])->toBe(5)
        ->and($m['rankings']['top10'])->toBe(12);
});

it('returns honest empty cards when the spine is bare', function () {
    $site = Site::factory()->create();

    $m = app(SiteDashboard::class)->metrics($site);

    expect($m['pagespeed']['empty'])->toBeTrue()
        ->and($m['impressions']['empty'])->toBeTrue()
        ->and($m['avg_position']['empty'])->toBeTrue()
        ->and($m['sessions']['empty'])->toBeTrue()
        ->and($m['indexed']['empty'])->toBeTrue()
        ->and($m['not_indexed']['empty'])->toBeTrue()
        ->and($m['keywords']['empty'])->toBeTrue()
        ->and($m['rankings']['empty'])->toBeTrue()
        ->and($m['data_through'])->toBeNull();
});

it('scopes metrics to the passed tenant only', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    tdSnap($a, 'ga4', 'sessions', 'site', '', now()->toDateString(), 500);
    tdSnap($b, 'ga4', 'sessions', 'site', '', now()->toDateString(), 7);

    expect(app(SiteDashboard::class)->metrics($a)['sessions']['value'])->toBe(500)
        ->and(app(SiteDashboard::class)->metrics($b)['sessions']['value'])->toBe(7);
});

it('makes NO outbound provider call at render — every card is a persisted read (acceptance 16)', function () {
    $site = Site::factory()->create();
    tdSeedSite($site);

    Http::preventStrayRequests();
    Http::fake();

    app(SiteDashboard::class)->metrics($site);

    Http::assertNothingSent();
});

it('renders the dashboard for the locked tenant with no stray HTTP', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $site = Site::factory()->create(['brand_name' => 'Gamma Plumbing']);
    tdSeedSite($site);
    app(ActiveTenant::class)->set($site->id);

    Http::preventStrayRequests();
    Http::fake();

    Livewire::test(TenantDashboard::class)
        ->assertOk()
        ->assertSee('Gamma Plumbing')
        ->assertSee('Search impressions')
        ->assertSee('PageSpeed')
        ->assertSee('Areas');

    Http::assertNothingSent();
});

it('offers the eleven area cards and flags the provisional targets', function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));

    $site = Site::factory()->create();
    app(ActiveTenant::class)->set($site->id);

    $areas = Livewire::test(TenantDashboard::class)->assertOk()->instance()->areas;

    expect($areas)->toHaveCount(11)
        ->and(collect($areas)->pluck('label')->all())->toBe([
            'Setup', 'Posts', 'Pages', 'Jobs', 'Reviews', 'Live',
            'Markets', 'Targeting', 'Measure', 'Settings', 'Recover',
        ])
        // Every card links somewhere real (no dead cards).
        ->and(collect($areas)->every(fn (array $a): bool => str_contains($a['url'], '/admin/')))->toBeTrue()
        // Exactly the four undecided homes are marked provisional.
        ->and(collect($areas)->filter(fn (array $a): bool => $a['provisional'] ?? false)->pluck('label')->all())
        ->toBe(['Jobs', 'Markets', 'Measure', 'Recover']);
});
