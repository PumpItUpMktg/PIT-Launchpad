<?php

use App\Models\Market;
use App\Operator\Coverage\MarketHold;
use Illuminate\Support\Carbon;

it('holds a market with a target release date and lifts it', function () {
    $market = Market::factory()->create();
    $holds = app(MarketHold::class);

    $holds->hold($market, Carbon::parse('2026-10-01'));
    $market->refresh();
    expect($market->on_hold)->toBeTrue()
        ->and($market->release_at?->toDateString())->toBe('2026-10-01');

    $holds->release($market);
    $market->refresh();
    expect($market->on_hold)->toBeFalse()
        ->and($market->release_at)->toBeNull();
});

it('flags a hold as overdue only when held AND the release date has passed', function () {
    $overdue = Market::factory()->create(['on_hold' => true, 'release_at' => now()->subDay()]);
    $upcoming = Market::factory()->create(['on_hold' => true, 'release_at' => now()->addWeek()]);
    $notHeld = Market::factory()->create(['on_hold' => false, 'release_at' => now()->subDay()]);

    expect($overdue->releaseOverdue())->toBeTrue()
        ->and($upcoming->releaseOverdue())->toBeFalse()
        ->and($notHeld->releaseOverdue())->toBeFalse();
});

it('the market-hold command sets and lifts a hold', function () {
    $market = Market::factory()->create(['name' => 'Newark']);

    $this->artisan('launchpad:market-hold', ['market' => $market->id, '--until' => '2026-12-01'])
        ->expectsOutputToContain('Held Newark until 2026-12-01')
        ->assertSuccessful();
    expect($market->fresh()->on_hold)->toBeTrue();

    $this->artisan('launchpad:market-hold', ['market' => $market->id, '--lift' => true])
        ->expectsOutputToContain('Released the hold on Newark')
        ->assertSuccessful();
    expect($market->fresh()->on_hold)->toBeFalse();
});

it('the command errors without --until or --lift, and on an unknown market', function () {
    $market = Market::factory()->create();

    $this->artisan('launchpad:market-hold', ['market' => $market->id])->assertFailed();
    $this->artisan('launchpad:market-hold', ['market' => 'nope', '--lift' => true])->assertFailed();
});
