<?php

use App\Enums\UserRole;
use App\Filament\Client\Widgets\TownVisibilityWidget;
use App\Models\CoverageArea;
use App\Models\Keyword;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Site;
use App\Models\TownRankPoint;
use App\Models\TownRankScan;
use App\Models\User;
use App\TownRank\TownRankBoard;
use App\TownRank\TownVisibility;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\ClientHarness;

/** Three towns — 10k, 7k, 5k people — and one tracked keyword. */
function visibilitySite(): array
{
    $site = Site::factory()->create(['brand_name' => 'SPG', 'domain_url' => 'https://spg.com']);
    $loc = Location::factory()->create(['site_id' => $site->id, 'lat' => 40.85, 'lng' => -74.83]);
    $towns = [
        CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Big', 'state' => 'NJ', 'population' => 10000, 'lat' => 40.85, 'lng' => -74.83, 'source_location_ids' => [$loc->id], 'geo_id' => '3400000001']),
        CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Mid', 'state' => 'NJ', 'population' => 7000, 'lat' => 40.80, 'lng' => -74.85, 'source_location_ids' => [$loc->id], 'geo_id' => '3400000002']),
        CoverageArea::factory()->create(['site_id' => $site->id, 'name' => 'Small', 'state' => 'NJ', 'population' => 5000, 'lat' => 40.90, 'lng' => -74.80, 'source_location_ids' => [$loc->id], 'geo_id' => '3400000003']),
    ];
    $kw = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump service', 'track_town_rank' => true]);

    return [$site, $kw, $towns];
}

/** @param array<int, int|null> $ranks rank per town, in the towns' order */
function visibilityScan(Site $site, Keyword $kw, array $towns, array $ranks, string $scannedAt, string $mode = 'town_query'): TownRankScan
{
    $scan = TownRankScan::create(['site_id' => $site->id, 'keyword_id' => $kw->id, 'mode' => $mode, 'status' => 'complete', 'points_count' => count($towns), 'found_count' => count(array_filter($ranks)), 'scanned_at' => $scannedAt]);
    foreach ($towns as $i => $t) {
        TownRankPoint::create(['site_id' => $site->id, 'scan_id' => $scan->id, 'coverage_area_id' => $t->id, 'geo_id' => $t->geo_id, 'label' => $t->name, 'state' => 'NJ', 'lat' => $t->lat, 'lng' => $t->lng, 'query' => 'q', 'rank' => $ranks[$i], 'collected_at' => $scannedAt]);
    }

    return $scan;
}

it('scores a keyword by population-weighted rank credit, and the movement against the previous scan', function () {
    [$site, $kw, $towns] = visibilitySite();
    // Last week: #2 in Big (10k, credit 1), #8 in Mid (7k, .7), not found in Small (5k, 0) → (10000 + 4900) / 22000 = 68.
    visibilityScan($site, $kw, $towns, [2, 8, null], '2026-09-21 06:00:00');
    // This week: #1 Big, #4 Mid, #15 Small (page 2, .3) → (10000 + 4900 + 1500) / 22000 = 75.
    visibilityScan($site, $kw, $towns, [1, 4, 15], '2026-09-28 06:00:00');

    $v = app(TownVisibility::class)->forKeyword($site, $kw);

    expect($v['town_query'])->toMatchArray(['score' => 75, 'previous' => 68, 'delta' => 7, 'towns' => 3, 'page1_towns' => 2, 'top3_towns' => 1])
        ->and($v['local']['score'])->toBeNull();   // that mode was never scanned

    // Credit bands: top-3 full, page 1 most, page 2 a little, beyond / not found nothing.
    expect(TownVisibility::creditFor(3))->toBe(1.0)->and(TownVisibility::creditFor(10))->toBe(0.7)
        ->and(TownVisibility::creditFor(20))->toBe(0.3)->and(TownVisibility::creditFor(21))->toBe(0.0)->and(TownVisibility::creditFor(null))->toBe(0.0);
});

it('rolls the site up: mean of the tracked keywords, page-1 pairs summed, and a weekly trend from every finished scan', function () {
    [$site, $kw, $towns] = visibilitySite();
    $kw2 = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'french drain', 'track_town_rank' => true]);
    visibilityScan($site, $kw, $towns, [2, 8, null], '2026-09-14 06:00:00');    // 68
    visibilityScan($site, $kw, $towns, [2, 8, null], '2026-09-21 06:00:00');    // 68
    visibilityScan($site, $kw, $towns, [1, 4, 15], '2026-09-28 06:00:00');      // 75
    visibilityScan($site, $kw2, $towns, [null, null, 3], '2026-09-21 06:00:00'); // 5000 / 22000 = 23
    visibilityScan($site, $kw2, $towns, [null, 9, 3], '2026-09-28 06:00:00');    // (4900 + 5000) / 22000 = 45

    $site_ = app(TownVisibility::class)->forSite($site)['town_query'];

    expect($site_['score'])->toBe(60)          // mean(75, 45)
        ->and($site_['previous'])->toBe(46)    // mean(68, 23) = 45.5 → 46
        ->and($site_['delta'])->toBe(14)
        ->and($site_['keywords'])->toBe(2)
        ->and($site_['page1_towns'])->toBe(4)  // 2 + 2
        ->and(array_column($site_['history'], 'date'))->toBe(['2026-09-14', '2026-09-21', '2026-09-28'])
        ->and(array_column($site_['history'], 'score'))->toBe([68, 46, 60]);
});

it('puts the score on the operator card and on the client dashboard, and says nothing until a scan has finished', function () {
    [$site, $kw, $towns] = visibilitySite();
    visibilityScan($site, $kw, $towns, [2, 8, null], '2026-09-21 06:00:00');
    visibilityScan($site, $kw, $towns, [1, 4, 15], '2026-09-28 06:00:00');

    $card = app(TownRankBoard::class)->cards($site)[0];
    expect($card['visibility']['town_query'])->toMatchArray(['score' => 75, 'delta' => 7])
        ->and($card['visibility']['local'])->toBeNull();

    // The client widget reads the same number — a client of THIS site.
    $client = User::factory()->create(['role' => UserRole::Client]);
    Membership::create(['user_id' => $client->id, 'account_id' => $site->account_id, 'site_id' => $site->id, 'role' => UserRole::Client]);
    Filament::setCurrentPanel('client');
    $this->actingAs($client);
    Livewire::test(TownVisibilityWidget::class)->assertOk()->assertSee('75 / 100')->assertSee('up 7 since the last scan');

    // A site with no finished scan shows no visibility stat at all — nothing is claimed.
    ['user' => $other] = ClientHarness::make();
    $this->actingAs($other);
    Livewire::test(TownVisibilityWidget::class)->assertOk()->assertDontSee('/ 100');
});

it('measures movement against the mean of the previous two finished scans, so one bouncy week is not a trend', function () {
    [$site, $kw, $towns] = visibilitySite();
    visibilityScan($site, $kw, $towns, [2, 8, null], '2026-09-14 06:00:00');    // 68
    visibilityScan($site, $kw, $towns, [null, 8, null], '2026-09-21 06:00:00'); // 4900 / 22000 = 22 — a bounce
    visibilityScan($site, $kw, $towns, [1, 4, 15], '2026-09-28 06:00:00');      // 75

    $v = app(TownVisibility::class)->forKeyword($site, $kw)['town_query'];

    expect($v['score'])->toBe(75)
        ->and($v['previous'])->toBe(45)          // mean(68, 22), not the bounce alone
        ->and($v['delta'])->toBe(30)
        ->and($v['baseline_scans'])->toBe(2);

    // The client widget names the baseline it moved against, and shows town search only.
    $client = User::factory()->create(['role' => UserRole::Client]);
    Membership::create(['user_id' => $client->id, 'account_id' => $site->account_id, 'site_id' => $site->id, 'role' => UserRole::Client]);
    visibilityScan($site, $kw, $towns, [1, 1, 1], '2026-09-28 06:30:00', 'local');
    Filament::setCurrentPanel('client');
    $this->actingAs($client);
    Livewire::test(TownVisibilityWidget::class)->assertOk()
        ->assertSee('up 30 vs the last two scans')
        ->assertDontSee('from the town');
});
