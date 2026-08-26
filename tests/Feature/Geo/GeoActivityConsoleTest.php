<?php

use App\Enums\GeoCheckAction;
use App\Enums\UserRole;
use App\Filament\Pages\GeoActivityConsole;
use App\Geo\GeoCheckStatus;
use App\Models\GeoCheckEvent;
use App\Models\GeoPrompt;
use App\Models\Site;
use App\Models\User;
use App\Support\CurrentSite;
use Filament\Facades\Filament;
use Livewire\Livewire;

afterEach(fn () => CurrentSite::clear());

beforeEach(fn () => Filament::setCurrentPanel('admin'));

it('is operator-only', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Client]));

    expect(GeoActivityConsole::canAccess())->toBeFalse();
});

it('renders the live console — engine lanes, the step feed, and the now-contacting cursor', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    GeoPrompt::create(['site_id' => $site->id, 'prompt' => 'q', 'active' => true]);

    GeoCheckEvent::create(['site_id' => $site->id, 'run_id' => 'run-1', 'engine' => 'claude', 'action' => GeoCheckAction::Measured->value, 'town' => 'Union', 'cited' => false, 'competitors' => ['Rival Plumbing']]);
    GeoCheckEvent::create(['site_id' => $site->id, 'run_id' => 'run-1', 'engine' => 'perplexity', 'action' => GeoCheckAction::Deferred->value, 'town' => 'Union']);
    app(GeoCheckStatus::class)->markContacting($site->id, 'perplexity', 'best sump pump repair in Union', 'Union');

    Livewire::test(GeoActivityConsole::class)
        ->set('siteId', $site->id)
        ->assertOk()
        ->assertSee('Claude')             // per-engine lane
        ->assertSee('Perplexity')
        ->assertSee('Union')              // feed town
        ->assertSee('Rival Plumbing')     // competitor on the absent measured step
        ->assertSee('Contacting');        // the live now-contacting cursor
});
