<?php

use App\Enums\UserRole;
use App\Filament\Pages\Lobby;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    Cache::forget('deploy_lag');
});

it('renders the platform deploy-lag notice ONCE when stale — never once per tenant card', function () {
    // A multi-tenant lobby: a stuck deploy affects all of them, so the notice must appear exactly once.
    Site::factory()->count(3)->create();
    Cache::forever('deploy_lag', [
        'behind' => 11, 'oldest_undeployed_at' => now()->subHours(9)->toIso8601String(),
        'deployed_short' => 'abc1234', 'checked_at' => now()->toIso8601String(),
    ]);

    $html = Livewire::test(Lobby::class)->assertOk()->assertSee('Deployment behind main')->html();

    expect(substr_count($html, 'Deployment behind main'))->toBe(1); // platform-level, not per-card
});

it('hides the notice when the deploy is fresh or merely late (a deploy plausibly in flight)', function () {
    Site::factory()->create();

    Cache::forever('deploy_lag', ['behind' => 1, 'oldest_undeployed_at' => now()->subHours(2)->toIso8601String()]);
    Livewire::test(Lobby::class)->assertOk()->assertDontSee('Deployment behind main');

    Cache::forget('deploy_lag'); // never checked → nothing to show
    Livewire::test(Lobby::class)->assertOk()->assertDontSee('Deployment behind main');
});
