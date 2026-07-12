<?php

use App\Build\BuildManifestAssembler;
use App\Enums\SetupStep;
use App\Enums\SpokeStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Guided\Plan;
use App\Models\BuildPage;
use App\Models\SetupState;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\Spoke;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The cards-first face of the merged Plan step (setup-redesign relay): the page inventory leads
 * as cards, the standard-page toggles curate the build, and Approve is the button at the bottom.
 */
beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $this->site = Site::factory()->create();
    session(['guided_site_id' => $this->site->id]);
});

/** A ready structure so the plan renders its cards (not the building spinner). */
function planReady(Site $site): void
{
    $bp = SiloBlueprint::factory()->create(['site_id' => $site->id]);
    Spoke::factory()->create(['site_id' => $site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Pumps', 'name' => 'Pumps', 'is_pillar' => true, 'status' => SpokeStatus::Offered]);
}

test('the plan renders the blueprint pages as cards with Approve at the bottom', function () {
    SetupState::query()->create([
        'site_id' => $this->site->id, 'current_step' => 5,
        'services_done' => true, 'territory_done' => true,
    ]);
    planReady($this->site);

    Livewire::test(Plan::class)
        ->assertOk()
        ->assertSee('Your website plan')
        ->assertSee('Pumps')                       // the service hub card
        ->assertSee('Core pages — the basics every site needs')
        ->assertSee('Adjust structure')            // the demoted tree panel
        ->assertSee('Approve & build my site');    // approve is a button, not a step
});

test('approve completes the whole plan step — finalize + review are implicit, current_step jumps to Grow', function () {
    SetupState::query()->create([
        'site_id' => $this->site->id, 'current_step' => 5,
        'services_done' => true, 'territory_done' => true,
    ]);
    planReady($this->site);

    Livewire::test(Plan::class)->call('approveAndBuild');

    $state = SetupState::query()->where('site_id', $this->site->id)->first();
    expect($state->structure_finalized)->toBeTrue()          // the old Structure finalize
        ->and($state->inventory_reviewed)->toBeTrue()        // the old Inventory pass-through
        ->and($state->approved)->toBeTrue()
        ->and($state->isComplete(SetupStep::Plan))->toBeTrue()
        ->and($state->current_step)->toBe(SetupStep::Grow->value);
});

test('the plan is freely reachable before anything is built — tabs, not a gated wizard', function () {
    // Fresh state — it renders (with nothing to offer yet) instead of redirecting to step 1.
    Livewire::test(Plan::class)->assertOk();
});

test('entering the plan seeds the offerable optionals ON (default-selected)', function () {
    SetupState::query()->create([
        'site_id' => $this->site->id, 'current_step' => 5,
        'services_done' => true, 'territory_done' => true,
    ]);

    Livewire::test(Plan::class)->assertOk();

    // Why Choose Us + FAQ are always offerable → seeded accepted on first visit.
    $accepted = SetupState::query()->where('site_id', $this->site->id)->value('standard_pages');
    expect($accepted['faq'] ?? false)->toBeTrue()
        ->and($accepted['why_choose_us'] ?? false)->toBeTrue();
});

test('toggling an optional foundation page curates the build manifest', function () {
    SetupState::query()->create([
        'site_id' => $this->site->id, 'current_step' => 5,
        'services_done' => true, 'territory_done' => true,
    ]);
    planReady($this->site);

    $page = Livewire::test(Plan::class); // mount seeds faq ON

    // The toggle is bound to Livewire (wire:click action) and the loop item carries a unique key
    // so the morph updates the right node in the browser.
    $page->assertSeeHtml('wire:click="toggleStandard(\'faq\')"')
        ->assertSeeHtml('wire:key="found-faq"');

    $page->call('toggleStandard', 'faq'); // → off
    expect(SetupState::query()->where('site_id', $this->site->id)->value('standard_pages')['faq'])->toBeFalse();
    // the rendered markup reflects the deselected state (not just the persisted value)
    $page->assertSeeHtml('class="lp-pageitem off"');

    $page->call('toggleStandard', 'faq'); // → on, persists through re-render
    expect(SetupState::query()->where('site_id', $this->site->id)->value('standard_pages')['faq'])->toBeTrue()
        ->and(collect($page->instance()->inventory['foundation'])->firstWhere('type', 'faq')['accepted'])->toBeTrue();
    $page->assertSeeHtml('class="lp-pageitem opt on"');

    // the curated selection flows into the assembled build manifest
    app(BuildManifestAssembler::class)->assemble($this->site->fresh());
    expect(BuildPage::query()->where('site_id', $this->site->id)->where('page_key', 'faq')->exists())->toBeTrue();
});
