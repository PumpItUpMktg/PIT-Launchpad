<?php

use App\Build\BuildRunner;
use App\Enums\ArrangeFlagType;
use App\Enums\UserRole;
use App\Filament\Pages\Guided\Approve;
use App\Filament\Pages\Guided\Build;
use App\Filament\Pages\Guided\Business;
use App\Filament\Pages\Guided\Grow;
use App\Filament\Pages\Guided\Structure;
use App\Filament\Pages\Guided\Territory;
use App\Models\ArrangementFlag;
use App\Models\BuildPage;
use App\Models\SetupState;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $this->site = Site::factory()->create();
    session(['guided_site_id' => $this->site->id]);
});

function setupState(Site $site): SetupState
{
    return SetupState::query()->firstOrCreate(['site_id' => $site->id]);
}

test('a step beyond the furthest-unlocked redirects back to the current step on mount', function () {
    // fresh state: only step 1 is open
    Livewire::test(Structure::class)->assertRedirect(Business::getUrl());
    Livewire::test(Territory::class)->assertRedirect(Business::getUrl());
});

test('completing step 1 advances and unlocks step 2', function () {
    Livewire::test(Business::class)
        ->call('proceed')
        ->assertRedirect(Territory::getUrl());

    $state = setupState($this->site);
    expect($state->services_done)->toBeTrue()
        ->and($state->current_step)->toBe(2);

    // step 2 now mounts without redirect
    Livewire::test(Territory::class)->assertOk();
});

test('Finalize is blocked while an auto-arrange flag is unresolved, then allowed once clear', function () {
    // unlock Structure: services + territory done, sitting on step 3
    setupState($this->site)->update(['services_done' => true, 'territory_done' => true, 'current_step' => 3]);

    $spoke = Spoke::factory()->create(['site_id' => $this->site->id]);
    $flag = ArrangementFlag::query()->create([
        'site_id' => $this->site->id, 'spoke_id' => $spoke->id, 'type' => ArrangeFlagType::DedupAmbiguous,
        'message' => 'x', 'candidates' => [], 'alternative' => [], 'score' => 0.9,
    ]);

    // Finalize is gated — no advance.
    Livewire::test(Structure::class)
        ->assertOk()
        ->call('finalize');
    expect(setupState($this->site)->fresh()->structure_finalized)->toBeFalse();

    // Resolve the flag → finalize advances to Approve.
    $flag->delete();
    Livewire::test(Structure::class)
        ->call('finalize')
        ->assertRedirect(Approve::getUrl());
    expect(setupState($this->site)->fresh()->structure_finalized)->toBeTrue();
});

test('the full walkthrough advances 1 → 2 → 3 → 4 → Build → Grow and launches', function () {
    Livewire::test(Business::class)->call('proceed');
    Livewire::test(Territory::class)->call('proceed');

    // Simulate a built structure so Step 3 is "ready" (the engine chain is covered separately).
    $bp = SiloBlueprint::factory()->create(['site_id' => $this->site->id]);
    Spoke::factory()->create(['site_id' => $this->site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Pumps', 'name' => 'Pumps', 'is_pillar' => true]);

    Livewire::test(Structure::class)->call('finalize'); // ready, no flags → passes

    // Approve assembles the manifest and hands off to Build (not straight to Grow).
    Livewire::test(Approve::class)->call('approveAndBuild')->assertRedirect(Build::getUrl());

    $state = setupState($this->site)->fresh();
    expect($state->approved)->toBeTrue()
        ->and($state->launched)->toBeFalse()                 // Build sets launched, not Approve
        ->and(BuildPage::query()->where('site_id', $this->site->id)->exists())->toBeTrue();

    // Build phase: entering ticks the queue (auto publish / gate brand-critical), then review.
    Livewire::test(Build::class)->assertOk();
    foreach (BuildPage::query()->where('site_id', $this->site->id)->where('status', 'in_review')->get() as $page) {
        app(BuildRunner::class)->publishReviewed($this->site, $page);
    }

    $state = setupState($this->site)->fresh();
    expect($state->launched)->toBeTrue();

    // Grow is now unlocked (prerequisite: launched).
    Livewire::test(Grow::class)->assertOk();
});
