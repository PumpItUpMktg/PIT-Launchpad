<?php

use App\Enums\GeoCheckAction;
use App\Enums\UserRole;
use App\Filament\Pages\GeoActivityConsole;
use App\Geo\GeoCheckStatus;
use App\Models\GeoCheckEvent;
use App\Models\GeoPrompt;
use App\Models\GeoSnapshot;
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

    $prompt = GeoPrompt::create(['site_id' => $site->id, 'prompt' => 'best sump pump repair in Union', 'active' => true]);
    GeoCheckEvent::create(['site_id' => $site->id, 'run_id' => 'run-1', 'engine' => 'claude', 'geo_prompt_id' => $prompt->id, 'action' => GeoCheckAction::Measured->value, 'town' => 'Union', 'cited' => false, 'competitors' => ['Rival Plumbing']]);
    GeoCheckEvent::create(['site_id' => $site->id, 'run_id' => 'run-1', 'engine' => 'perplexity', 'action' => GeoCheckAction::Deferred->value, 'town' => 'Union']);
    GeoSnapshot::create(['site_id' => $site->id, 'geo_prompt_id' => $prompt->id, 'engine' => 'claude', 'cited' => false, 'answer_excerpt' => 'For sump pump repair in Union, Rival Plumbing is often recommended.', 'checked_at' => now()]);
    app(GeoCheckStatus::class)->markContacting($site->id, 'perplexity', 'best sump pump repair in Union', 'Union');

    Livewire::test(GeoActivityConsole::class)
        ->set('siteId', $site->id)
        ->assertOk()
        ->assertSee('Claude')                          // per-engine lane
        ->assertSee('Perplexity')
        ->assertSee('Union')                           // feed town
        ->assertSee('Rival Plumbing')                  // competitor on the absent measured step
        ->assertSee('best sump pump repair in Union')  // the prompt each step measured
        ->assertSee('is often recommended')            // the engine's printed response prose
        ->assertSee('Contacting');                     // the live now-contacting cursor
});

it('prints the cached answer on a skipped-fresh step too (the fresh re-run case)', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $prompt = GeoPrompt::create(['site_id' => $site->id, 'prompt' => 'sump pump install Cranford', 'active' => true]);

    // A re-run within the freshness window: the step is skipped-fresh, not re-measured...
    GeoCheckEvent::create(['site_id' => $site->id, 'run_id' => 'run-2', 'engine' => 'claude', 'geo_prompt_id' => $prompt->id, 'action' => GeoCheckAction::SkippedFresh->value, 'town' => 'Cranford']);
    // ...but its prior snapshot still holds the answer, which should still print.
    GeoSnapshot::create(['site_id' => $site->id, 'geo_prompt_id' => $prompt->id, 'engine' => 'claude', 'cited' => true, 'answer_excerpt' => 'SPG installs sump pumps across Cranford.', 'checked_at' => now()->subHour()]);

    Livewire::test(GeoActivityConsole::class)
        ->set('siteId', $site->id)
        ->assertOk()
        ->assertSee('installs sump pumps across Cranford');   // cached answer prints on the fresh step
});

it('surfaces the measured answer even behind a wall of newer deferred rows', function () {
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $site = Site::factory()->create(['brand_name' => 'SPG']);
    $prompt = GeoPrompt::create(['site_id' => $site->id, 'prompt' => 'sump pump repair Ardmore', 'active' => true]);

    // One measured step early in the run...
    GeoCheckEvent::create(['site_id' => $site->id, 'run_id' => 'run-3', 'engine' => 'claude', 'geo_prompt_id' => $prompt->id, 'action' => GeoCheckAction::Measured->value, 'town' => 'Ardmore', 'cited' => true, 'created_at' => now()->subMinutes(5)]);
    GeoSnapshot::create(['site_id' => $site->id, 'geo_prompt_id' => $prompt->id, 'engine' => 'claude', 'cited' => true, 'answer_excerpt' => 'SPG is a top choice for sump pump repair in Ardmore.', 'checked_at' => now()->subMinutes(5)]);
    // ...then 40 newer deferred steps (budget ran out) that would fill the newest-30 window.
    foreach (range(1, 40) as $i) {
        GeoCheckEvent::create(['site_id' => $site->id, 'run_id' => 'run-3', 'engine' => 'claude', 'action' => GeoCheckAction::Deferred->value, 'town' => "Town {$i}", 'created_at' => now()->subMinutes(4)->addSeconds($i)]);
    }

    Livewire::test(GeoActivityConsole::class)
        ->set('siteId', $site->id)
        ->assertOk()
        ->assertSee('top choice for sump pump repair in Ardmore');   // answer surfaces despite 40 newer deferred rows
});
