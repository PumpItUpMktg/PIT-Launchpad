<?php

use App\Build\BuildManifestAssembler;
use App\Enums\SpokeStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Guided\Approve;
use App\Filament\Pages\Guided\Business;
use App\Filament\Pages\Guided\Inventory;
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

test('the inventory renders the blueprint pages and Continue carries into Approve', function () {
    SetupState::query()->create([
        'site_id' => $this->site->id, 'current_step' => 4,
        'services_done' => true, 'territory_done' => true, 'structure_finalized' => true,
    ]);
    $bp = SiloBlueprint::factory()->create(['site_id' => $this->site->id]);
    Spoke::factory()->create(['site_id' => $this->site->id, 'silo_blueprint_id' => $bp->id, 'silo' => 'Pumps', 'name' => 'Pumps', 'is_pillar' => true, 'status' => SpokeStatus::Offered]);

    Livewire::test(Inventory::class)
        ->assertOk()
        ->assertSee('Page inventory')
        ->assertSee('Pumps')
        ->call('proceed')
        ->assertRedirect(Approve::getUrl());
});

test('the inventory is gated until the structure is finalized', function () {
    // fresh state — only step 1 open
    Livewire::test(Inventory::class)->assertRedirect(Business::getUrl());
});

test('entering the inventory seeds the offerable optionals ON (default-selected)', function () {
    SetupState::query()->create([
        'site_id' => $this->site->id, 'current_step' => 4,
        'services_done' => true, 'territory_done' => true, 'structure_finalized' => true,
    ]);

    Livewire::test(Inventory::class)->assertOk();

    // Why Choose Us + FAQ are always offerable → seeded accepted on first visit.
    $accepted = SetupState::query()->where('site_id', $this->site->id)->value('standard_pages');
    expect($accepted['faq'] ?? false)->toBeTrue()
        ->and($accepted['why_choose_us'] ?? false)->toBeTrue();
});

test('toggling an optional foundation page curates the build manifest', function () {
    SetupState::query()->create([
        'site_id' => $this->site->id, 'current_step' => 4,
        'services_done' => true, 'territory_done' => true, 'structure_finalized' => true,
    ]);

    $page = Livewire::test(Inventory::class); // mount seeds faq ON

    $page->call('toggleStandard', 'faq'); // → off
    expect(SetupState::query()->where('site_id', $this->site->id)->value('standard_pages')['faq'])->toBeFalse();

    $page->call('toggleStandard', 'faq'); // → on, persists through re-render
    expect(SetupState::query()->where('site_id', $this->site->id)->value('standard_pages')['faq'])->toBeTrue()
        ->and(collect($page->instance()->inventory['foundation'])->firstWhere('type', 'faq')['accepted'])->toBeTrue();

    // the curated selection flows into the assembled build manifest
    app(BuildManifestAssembler::class)->assemble($this->site->fresh());
    expect(BuildPage::query()->where('site_id', $this->site->id)->where('page_key', 'faq')->exists())->toBeTrue();
});
