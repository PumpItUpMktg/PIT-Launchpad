<?php

use App\Enums\SpokeStatus;
use App\Enums\UserRole;
use App\Filament\Pages\Guided\Approve;
use App\Filament\Pages\Guided\Business;
use App\Filament\Pages\Guided\Inventory;
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
