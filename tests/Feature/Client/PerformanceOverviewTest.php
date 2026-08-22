<?php

use App\Client\Dashboard\Sparkline;
use App\Enums\UserRole;
use App\Filament\Client\Pages\Insights;
use App\Filament\Client\Pages\PerformanceOverview;
use App\Models\ClientMilestone;
use App\Models\Site;
use App\Models\User;
use App\Support\CurrentSite;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ClientHarness;

afterEach(function () {
    CurrentSite::clear();
});

function poSnap(Site $site, string $provider, string $metric, string $dimType, string $dimValue, string $date, float $value): void
{
    DB::table('metric_snapshots')->insert([
        'id' => (string) Str::ulid(), 'site_id' => $site->id, 'provider' => $provider, 'metric_key' => $metric,
        'dimension_type' => $dimType, 'dimension_value' => $dimValue, 'period_grain' => 'day', 'period_date' => $date,
        'value_numeric' => $value, 'value_json' => null, 'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('maps a series to polyline points and a closed area path', function () {
    // Two points across a 100×100 box, pad 0: min at bottom, max at top-right.
    expect(Sparkline::points([0, 10], 100, 100, 10.0, 0))->toBe('0,100 100,0')
        ->and(Sparkline::points([], 100, 100))->toBe('')
        ->and(Sparkline::points([5], 100, 100, 10.0, 0))->toBe('100,50'); // single point sits at right edge

    $area = Sparkline::areaPath([0, 10], 100, 100, 10.0, 0);
    expect($area)->toStartWith('M0,100 L100,0')->toEndWith('L100,100 L0,100 Z');
});

it('renders the performance landing for a client with spine data', function () {
    ['user' => $client, 'site' => $site] = ClientHarness::make(['brand_name' => 'Apex Waterproofing']);
    Filament::setCurrentPanel('client');
    $this->actingAs($client);

    poSnap($site, 'gsc', 'impressions', 'site', '', now()->subDays(3)->toDateString(), 100);
    poSnap($site, 'gsc', 'clicks', 'site', '', now()->subDays(3)->toDateString(), 9);
    poSnap($site, 'gsc', 'impressions', 'page', '/sump-pump', now()->subDays(3)->toDateString(), 60);
    poSnap($site, 'index', 'pages_indexed', 'site', '', now()->subDays(1)->toDateString(), 12);
    poSnap($site, 'index', 'pages_known', 'site', '', now()->subDays(1)->toDateString(), 15);
    poSnap($site, 'dataforseo', 'keywords_top10', 'site', '', now()->subDays(1)->toDateString(), 4);
    ClientMilestone::withoutGlobalScopes()->create(['site_id' => $site->id, 'key' => 'first_page_indexed', 'occurred_on' => now()->subDays(5)->toDateString(), 'is_client_visible' => true]);

    Livewire::test(PerformanceOverview::class)
        ->assertOk()
        ->assertSee('Pages working')
        ->assertSee('Keywords improved')
        ->assertSee('Pages Google added')
        ->assertSee('Google indexed your first page');
});

it('switches the time frame', function () {
    ['user' => $client, 'site' => $site] = ClientHarness::make();
    Filament::setCurrentPanel('client');
    $this->actingAs($client);
    poSnap($site, 'gsc', 'impressions', 'site', '', now()->subDays(3)->toDateString(), 100); // gives a launch anchor

    Livewire::test(PerformanceOverview::class)
        ->call('setFrame', 'last_28')
        ->assertSet('frameKey', 'last_28')
        ->assertOk();
});

it('shows an honest empty state when there is no data', function () {
    ['user' => $client] = ClientHarness::make(); // site exists but no spine rows
    Filament::setCurrentPanel('client');
    $this->actingAs($client);

    Livewire::test(PerformanceOverview::class)
        ->assertOk()
        ->assertSee('collecting'); // the "still collecting" empty copy
});

it('keeps the §7c widgets reachable on the Insights page', function () {
    ['user' => $client] = ClientHarness::make();
    Filament::setCurrentPanel('client');
    $this->actingAs($client);

    Livewire::test(Insights::class)->assertOk();
});

it('is client-gated — an operator cannot reach the client panel page', function () {
    $operator = User::factory()->create(['role' => UserRole::Operator]);
    $clientPanel = Filament::getPanel('client');

    expect($operator->canAccessPanel($clientPanel))->toBeFalse();
});
