<?php

use App\Enums\UserRole;
use App\Filament\Pages\Guided\Business;
use App\Filament\Pages\Guided\ConnectWordpress;
use App\Models\Scopes\SiteScope;
use App\Models\SetupState;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $this->site = Site::factory()->create();
    session(['guided_site_id' => $this->site->id]);
});

test('Step 1 persists a SiloSeed (trade + stated + confirmed suggestions) and advances', function () {
    Livewire::test(Business::class)
        ->set('businessName', 'Sump Pump Gurus')
        ->set('trade', 'Basement waterproofing')
        ->set('services', ['Sump pump installation'])
        ->set('suggestions', [
            ['name' => 'Battery backup sump pumps', 'why' => 'outages', 'on' => true],
            ['name' => 'French drains', 'why' => 'drainage', 'on' => false],
        ])
        ->call('proceed')
        ->assertRedirect(ConnectWordpress::getUrl());

    $blueprint = SiloBlueprint::withoutGlobalScope(SiteScope::class)->where('site_id', $this->site->id)->firstOrFail();

    expect($blueprint->trade)->toBe('Basement waterproofing')
        ->and($blueprint->seed['anchor_services'])->toContain('Sump pump installation')
        ->and($blueprint->seed['anchor_services'])->toContain('Battery backup sump pumps') // confirmed suggestion folded in
        ->and($blueprint->seed['anchor_services'])->not->toContain('French drains')          // unconfirmed dropped
        ->and($blueprint->seed['suggested_confirmed'])->toBe(['Battery backup sump pumps'])
        ->and($this->site->fresh()->brand_name)->toBe('Sump Pump Gurus');

    // services_done gate set
    expect(SetupState::query()->where('site_id', $this->site->id)->value('services_done'))->toBe(true);
});

test('add / remove service mutate the stated list', function () {
    Livewire::test(Business::class)
        ->set('newService', 'Grinder pumps')
        ->call('addService')
        ->assertSet('services', ['Grinder pumps'])
        ->assertSet('newService', '')
        ->call('removeService', 0)
        ->assertSet('services', []);
});
