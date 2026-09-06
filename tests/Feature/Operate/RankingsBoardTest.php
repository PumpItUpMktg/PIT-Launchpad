<?php

use App\Enums\BeatabilityLane;
use App\Enums\UserRole;
use App\Filament\Pages\RankingsBoard;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\PositionSnapshot;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use App\Operator\Coverage\RankingStandings;
use App\Ranking\OrganicMovers;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
});

function rankSnap(Site $s, Keyword $k, ?int $rank, $at, string $url = 'https://x/a'): void
{
    PositionSnapshot::factory()->create([
        'site_id' => $s->id, 'keyword_id' => $k->id, 'market_id' => null,
        'lane' => BeatabilityLane::Organic, 'rank' => $rank, 'ranking_url' => $url, 'captured_at' => $at,
    ]);
}

it('is operator-only', function () {
    expect(RankingsBoard::canAccess())->toBeTrue();
    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));
    expect(RankingsBoard::canAccess())->toBeFalse();
});

it('computes improved and newly-ranked movers, excluding flat/declined', function () {
    $site = Site::factory()->create();
    $improved = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'water heater repair hoboken']);
    $new = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'drain cleaning reading']);
    $flat = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'plumber montclair']);

    rankSnap($site, $improved, 22, now()->subMonth());
    rankSnap($site, $improved, 6, now());
    rankSnap($site, $new, null, now()->subMonth());
    rankSnap($site, $new, 9, now());
    rankSnap($site, $flat, 10, now()->subMonth());
    rankSnap($site, $flat, 12, now()); // slipped — not a mover

    $board = app(RankingStandings::class)->for($site->id);

    expect($board['summary']['tracked'])->toBe(3)
        ->and($board['summary']['improved'])->toBe(1)
        ->and($board['summary']['newly_ranked'])->toBe(1)
        ->and($board['movers'])->toHaveCount(2);

    // Best gain first: the improved one carries a delta; the flat keyword is absent.
    $first = $board['movers'][0];
    expect($first['query'])->toBe('water heater repair hoboken')
        ->and($first['delta'])->toBe(16)
        ->and(collect($board['movers'])->pluck('query'))->not->toContain('plumber montclair');
});

it('shares the movers kernel with the client dashboard (computed once)', function () {
    $site = Site::factory()->create();
    $k = Keyword::factory()->create(['site_id' => $site->id]);
    rankSnap($site, $k, 30, now()->subMonth());
    rankSnap($site, $k, 4, now());

    // Both the operator page's read-model and the shared kernel see the same mover.
    $viaKernel = app(OrganicMovers::class)->forSite($site->id);
    $viaBoard = app(RankingStandings::class)->for($site->id)['movers'];

    expect($viaKernel)->toHaveCount(1)
        ->and($viaBoard[0]['keyword_id'])->toBe($viaKernel[0]['keyword_id']);
});

it('flags cannibalization: two owned URLs in one latest capture', function () {
    $site = Site::factory()->create();
    $k = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'emergency plumber reading']);
    $at = now();
    rankSnap($site, $k, 5, $at, 'https://site/page-a');
    rankSnap($site, $k, 8, $at, 'https://site/page-b'); // same capture, second URL

    $board = app(RankingStandings::class)->for($site->id);

    expect($board['summary']['cannibalized'])->toBe(1)
        ->and($board['cannibalized'][0]['query'])->toBe('emergency plumber reading')
        ->and($board['cannibalized'][0]['urls'])->toBe(2);
});

it('summarizes local-pack standings per market', function () {
    $site = Site::factory()->create();
    $market = Market::factory()->create(['site_id' => $site->id, 'name' => 'Hoboken']);
    $k1 = Keyword::factory()->create(['site_id' => $site->id]);
    $k2 = Keyword::factory()->create(['site_id' => $site->id]);

    PositionSnapshot::factory()->create(['site_id' => $site->id, 'keyword_id' => $k1->id, 'market_id' => $market->id, 'lane' => BeatabilityLane::LocalPack, 'rank' => 2, 'captured_at' => now()]);
    PositionSnapshot::factory()->create(['site_id' => $site->id, 'keyword_id' => $k2->id, 'market_id' => $market->id, 'lane' => BeatabilityLane::LocalPack, 'rank' => 6, 'captured_at' => now()]);

    $local = app(RankingStandings::class)->for($site->id)['local'];

    expect($local)->toHaveCount(1)
        ->and($local[0]['market_name'])->toBe('Hoboken')
        ->and($local[0]['keywords'])->toBe(2)
        ->and($local[0]['avg_rank'])->toBe(4.0)
        ->and($local[0]['in_top3'])->toBe(1);
});

it('scopes rankings to the locked tenant', function () {
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    $ka = Keyword::factory()->create(['site_id' => $a->id]);
    $kb = Keyword::factory()->create(['site_id' => $b->id]);
    rankSnap($a, $ka, 20, now()->subMonth());
    rankSnap($a, $ka, 5, now());
    rankSnap($b, $kb, 20, now()->subMonth());
    rankSnap($b, $kb, 5, now());

    expect(app(RankingStandings::class)->for($a->id)['summary']['tracked'])->toBe(1);
});

it('exposes last_captured_at — null when never checked, the newest capture when present', function () {
    $site = Site::factory()->create();
    expect(app(RankingStandings::class)->for($site->id)['last_captured_at'])->toBeNull();

    $k = Keyword::factory()->create(['site_id' => $site->id]);
    rankSnap($site, $k, 5, now()->subDays(2));

    $last = app(RankingStandings::class)->for($site->id)['last_captured_at'];
    expect($last)->not->toBeNull()
        ->and(Carbon::parse($last)->isSameDay(now()->subDays(2)))->toBeTrue();
});

it('renders an honest never-checked freshness stamp when nothing has been sampled, not a blank board', function () {
    $site = Site::factory()->create();
    Keyword::factory()->create(['site_id' => $site->id]); // a keyword exists, but no snapshots captured
    app(ActiveTenant::class)->set($site->id);

    $html = Livewire::test(RankingsBoard::class)->assertOk()->html();

    expect($html)->toContain('never checked')
        ->and($html)->toContain('data-fresh-state="never_checked"');
});

it('renders a dated freshness stamp once positions have been captured', function () {
    $site = Site::factory()->create();
    $k = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump install hoboken']);
    rankSnap($site, $k, 25, now()->subMonth());
    rankSnap($site, $k, 7, now()->subDay());
    app(ActiveTenant::class)->set($site->id);

    $html = Livewire::test(RankingsBoard::class)->assertOk()->html();

    expect($html)->toContain('Rankings as of'); // dated stamp, not "never checked"
});

it('renders tenant-locked with movers and no per-page site picker', function () {
    $site = Site::factory()->create();
    $k = Keyword::factory()->create(['site_id' => $site->id, 'query' => 'sump pump install hoboken']);
    rankSnap($site, $k, 25, now()->subMonth());
    rankSnap($site, $k, 7, now());
    app(ActiveTenant::class)->set($site->id);

    $html = Livewire::test(RankingsBoard::class)->assertOk()->html();

    expect($html)->toContain('sump pump install hoboken')
        ->and($html)->toContain('Movers')
        ->and($html)->not->toContain('<select');
});
