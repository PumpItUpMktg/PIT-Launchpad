<?php

use App\Enums\ArrangeFlagType;
use App\Enums\ContentKind;
use App\Enums\SiteStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Guided\Business;
use App\Filament\Pages\Guided\ConnectWordpress;
use App\Filament\Pages\Guided\Grow;
use App\Filament\Pages\Guided\Plan;
use App\Filament\Pages\Guided\WhereYouWork;
use App\Models\ArrangementFlag;
use App\Models\BuildPage;
use App\Models\Content;
use App\Models\Scopes\SiteScope;
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
    $this->site = Site::factory()->create(['status' => SiteStatus::Onboarding]);
    session(['guided_site_id' => $this->site->id]);
});

function setupState(Site $site): SetupState
{
    return SetupState::query()->firstOrCreate(['site_id' => $site->id]);
}

test('every step is freely reachable — tabs, not a gated wizard (unified-menu relay)', function () {
    // Fresh state: earlier the gate redirected anything beyond step 1 back to Business. Setup is
    // now a step process between tabs — each step renders its own honest empty state instead.
    Livewire::test(WhereYouWork::class)->assertOk();
    Livewire::test(Plan::class)->assertOk();
});

test('completing step 1 advances and unlocks step 2 (Connect WordPress)', function () {
    Livewire::test(Business::class)
        ->call('proceed')
        ->assertRedirect(ConnectWordpress::getUrl());

    $state = setupState($this->site);
    expect($state->services_done)->toBeTrue()
        ->and($state->current_step)->toBe(2);

    // step 2 now mounts without redirect
    Livewire::test(ConnectWordpress::class)->assertOk();
});

test('Approve is blocked while an auto-arrange flag is unresolved, then allowed once clear', function () {
    // unlock Plan: services + territory done, sitting on step 5
    setupState($this->site)->update(['services_done' => true, 'territory_done' => true, 'current_step' => 5]);

    $spoke = Spoke::factory()->create(['site_id' => $this->site->id]);
    $flag = ArrangementFlag::query()->create([
        'site_id' => $this->site->id, 'spoke_id' => $spoke->id, 'type' => ArrangeFlagType::DedupAmbiguous,
        'message' => 'x', 'candidates' => [], 'alternative' => [], 'score' => 0.9,
    ]);

    // Approve is gated — the plan is neither finalized nor approved.
    Livewire::test(Plan::class)
        ->assertOk()
        ->call('approveAndBuild');
    $state = setupState($this->site)->fresh();
    expect($state->structure_finalized)->toBeFalse()
        ->and($state->approved)->toBeFalse();

    // Resolve the flag → approve finalizes the structure and hands off to Grow.
    $flag->delete();
    Livewire::test(Plan::class)
        ->call('approveAndBuild')
        ->assertRedirect(Grow::getUrl());
    expect(setupState($this->site)->fresh()->structure_finalized)->toBeTrue();
});

test('the full walkthrough advances Business → … → Plan approve → Grow and launches', function () {
    Livewire::test(Business::class)->call('proceed'); // → Connect WordPress

    // Steps 2-3 (Connect WordPress + Brand) are covered by their own tests; simulate their gates.
    setupState($this->site)->update(['deps_ready' => true, 'brand_pushed' => true, 'current_step' => 4]);

    // Step 4 (Where you work) — Continue carries into the plan.
    Livewire::test(WhereYouWork::class)->call('proceed')->assertRedirect(Plan::getUrl());
    expect(setupState($this->site)->fresh()->territory_done)->toBeTrue();

    // Simulate a built structure so the Plan is "ready" (the engine chain is covered separately).
    $bp = SiloBlueprint::factory()->create(['site_id' => $this->site->id]);
    Spoke::factory()->create(['site_id' => $this->site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Pumps', 'name' => 'Pumps', 'is_pillar' => true]);

    // Approve finalizes the structure implicitly, materializes the manifest into planned pages
    // (no AI), and hands off straight to Grow — Structure/Inventory/Finalize are no longer steps.
    Livewire::test(Plan::class)->call('approveAndBuild')->assertRedirect(Grow::getUrl());

    $state = setupState($this->site)->fresh();
    expect($state->structure_finalized)->toBeTrue()          // finalize is implicit in approve
        ->and($state->approved)->toBeTrue()
        ->and($state->launched)->toBeTrue()                  // handoff fires at materialize-complete
        ->and(BuildPage::query()->where('site_id', $this->site->id)->exists())->toBeTrue()
        ->and($this->site->fresh()->status)->toBe(SiteStatus::Active); // Onboarding → Active

    // The manifest is materialized into planned, undrafted pages — no generation has run.
    $pages = Content::withoutGlobalScope(SiteScope::class)
        ->where('site_id', $this->site->id)->where('kind', ContentKind::Page->value)->get();
    expect($pages)->not->toBeEmpty()
        ->and($pages->every(fn (Content $c) => ! $c->hasDraft()))->toBeTrue();

    // Grow is unlocked (prerequisite: launched) — no blocking build screen between approve and Grow.
    Livewire::test(Grow::class)->assertOk();
});
