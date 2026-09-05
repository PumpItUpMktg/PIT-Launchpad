<?php

use App\Enums\MarketTier;
use App\Enums\UserRole;
use App\Filament\Pages\MarketsBoard;
use App\Models\Content;
use App\Models\Keyword;
use App\Models\Market;
use App\Models\Site;
use App\Models\User;
use App\Operator\ActiveTenant;
use App\Operator\Coverage\MarketPortfolio;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

function operator(): User
{
    return User::factory()->create(['role' => UserRole::Operator]);
}

it('is operator-only', function () {
    expect(MarketsBoard::canAccess())->toBeFalse(); // no auth

    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));
    expect(MarketsBoard::canAccess())->toBeFalse();

    $this->actingAs(operator());
    expect(MarketsBoard::canAccess())->toBeTrue();
});

it('scopes markets to the locked tenant only', function () {
    $this->actingAs(operator());
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    Market::factory()->count(2)->create(['site_id' => $a->id]);
    Market::factory()->create(['site_id' => $b->id]);

    app(ActiveTenant::class)->set($a->id);

    $board = app(MarketPortfolio::class)->for($a->id);
    expect($board['summary']['total'])->toBe(2);
});

it('counts pages and keywords pinned to each market', function () {
    $this->actingAs(operator());
    $site = Site::factory()->create();
    $market = Market::factory()->create(['site_id' => $site->id, 'name' => 'Austin', 'tier' => MarketTier::Priority, 'demographics' => ['population' => 120000], 'is_covered' => true]);

    Content::factory()->count(3)->create(['site_id' => $site->id, 'market_id' => $market->id]);
    Keyword::factory()->count(2)->create(['site_id' => $site->id, 'market_id' => $market->id]);
    // An unpinned keyword must not be counted against the market.
    Keyword::factory()->create(['site_id' => $site->id, 'market_id' => null]);

    $row = collect(app(MarketPortfolio::class)->for($site->id)['markets'])->firstWhere('id', $market->id);

    expect($row['pages'])->toBe(3)
        ->and($row['keywords'])->toBe(2)
        ->and($row['population'])->toBe(120000)
        ->and($row['tier_label'])->toBe('Priority')
        ->and($row['is_covered'])->toBeTrue();
});

it('orders overdue holds first, then Priority tier, then name', function () {
    $this->actingAs(operator());
    $site = Site::factory()->create();

    Market::factory()->coverage()->create(['site_id' => $site->id, 'name' => 'Zephyr']);
    Market::factory()->priority()->create(['site_id' => $site->id, 'name' => 'Bexar']);
    // An overdue hold jumps to the top regardless of tier.
    Market::factory()->coverage()->create(['site_id' => $site->id, 'name' => 'Waco', 'on_hold' => true, 'release_at' => now()->subWeek()]);

    $names = collect(app(MarketPortfolio::class)->for($site->id)['markets'])->pluck('name')->all();

    expect($names)->toBe(['Waco', 'Bexar', 'Zephyr']); // overdue → priority → name
});

it('places and lifts an advisory hold through the board', function () {
    $this->actingAs(operator());
    $site = Site::factory()->create();
    $market = Market::factory()->create(['site_id' => $site->id, 'on_hold' => false, 'release_at' => null]);
    app(ActiveTenant::class)->set($site->id);

    // Place a hold via the header action.
    Livewire::test(MarketsBoard::class)
        ->callAction('placeHold', data: ['market' => $market->id, 'release_at' => now()->addMonth()->toDateString()]);

    $market->refresh();
    expect($market->on_hold)->toBeTrue()
        ->and($market->release_at)->not->toBeNull();

    // Lift it via the per-row release.
    Livewire::test(MarketsBoard::class)->call('release', $market->id);

    $market->refresh();
    expect($market->on_hold)->toBeFalse()
        ->and($market->release_at)->toBeNull();
});

it('never resolves a market outside the locked tenant on release', function () {
    $this->actingAs(operator());
    $a = Site::factory()->create();
    $b = Site::factory()->create();
    $foreign = Market::factory()->create(['site_id' => $b->id, 'on_hold' => true, 'release_at' => now()->addWeek()]);

    app(ActiveTenant::class)->set($a->id);
    Livewire::test(MarketsBoard::class)->call('release', $foreign->id);

    $foreign->refresh();
    expect($foreign->on_hold)->toBeTrue(); // untouched — belongs to another tenant
});

it('renders the tenant-locked board with markets and no per-page site picker', function () {
    $this->actingAs(operator());
    $site = Site::factory()->create();
    Market::factory()->create(['site_id' => $site->id, 'name' => 'Galveston', 'tier' => MarketTier::Priority]);
    app(ActiveTenant::class)->set($site->id);

    $html = Livewire::test(MarketsBoard::class)->assertOk()->html();

    expect($html)->toContain('Galveston')
        ->and($html)->toContain('Priority')
        ->and($html)->not->toContain('<select'); // tenant comes from the lock, never a page picker
});
