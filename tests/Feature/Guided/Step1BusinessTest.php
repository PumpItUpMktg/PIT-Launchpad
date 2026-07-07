<?php

use App\Enums\UserRole;
use App\Filament\Pages\Guided\Business;
use App\Filament\Pages\Guided\ConnectWordpress;
use App\Models\Location;
use App\Models\Scopes\SiteScope;
use App\Models\SetupState;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Models\User;
use App\Publishing\TenantStorage;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

test('captures the business phone + emergency line onto the site and mirrors to the primary location', function () {
    // A primary location exists (created earlier by territory) but carries no phone yet.
    $location = Location::factory()->create(['site_id' => $this->site->id, 'phone' => null]);

    Livewire::test(Business::class)
        ->set('businessName', 'Sewer Gurus')
        ->set('trade', 'Sewer repair')
        ->set('phone', '(973) 555-0100')
        ->set('emergencyPhone', '(973) 555-9111')
        ->call('proceed')
        ->assertRedirect(ConnectWordpress::getUrl());

    $site = $this->site->fresh();
    expect($site->phone)->toBe('(973) 555-0100')
        ->and($site->emergency_phone)->toBe('(973) 555-9111')
        // NAP stays consistent: the phone-less primary location inherits the business number
        ->and($location->fresh()->phone)->toBe('(973) 555-0100');
});

test('choosing a logo file uploads it and stores it on SiteBranding', function () {
    Storage::fake(TenantStorage::DISK);

    Livewire::test(Business::class)
        ->set('logo', UploadedFile::fake()->image('brand.png', 120, 60))
        ->assertHasNoErrors()
        ->assertSet('logo', null); // cleared after processing

    $branding = SiteBranding::withoutGlobalScope(SiteScope::class)->where('site_id', $this->site->id)->firstOrFail();
    expect($branding->logo_set['url'] ?? null)->not->toBeNull()
        ->and($branding->logo_set['ext'] ?? null)->toBe('png');
});

test('Step 1 is framed as "Add a new site" and the sidebar personalizes after a name', function () {
    // no brand yet → sidebar reads "Add a new site"; eyebrow always carries the creation framing
    $this->site->update(['brand_name' => '']);
    Livewire::test(Business::class)
        ->assertSee('Add a new site')
        ->assertSee('Step 1 of 7');

    $this->site->update(['brand_name' => 'Sewer Gurus']);
    Livewire::test(Business::class)->assertSee('Setting up Sewer Gurus');
});
