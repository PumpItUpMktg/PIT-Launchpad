<?php

use App\Enums\ProofType;
use App\Enums\UserRole;
use App\Filament\Pages\Guided\Business;
use App\Filament\Pages\Guided\ConnectWordpress;
use App\Models\Location;
use App\Models\ProofItem;
use App\Models\Scopes\SiteScope;
use App\Models\SetupState;
use App\Models\SiloBlueprint;
use App\Models\Site;
use App\Models\SiteBranding;
use App\Models\SiteNarrative;
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

test('captures the guarantee + certifications onto the site narrative, verbatim', function () {
    Livewire::test(Business::class)
        ->set('businessName', 'Sewer Gurus')
        ->set('trade', 'Sewer repair')
        ->set('guaranteeName', 'Forever Pump Warranty')
        ->set('guaranteeDescription', 'Free replacement for life.')
        ->set('newCertLabel', 'NJ Master Plumber')->set('newCertNumber', '#1234')->call('addCertification')
        ->set('newCertLabel', 'BBB A+')->call('addCertification')
        ->assertSet('certifications', [
            ['label' => 'NJ Master Plumber', 'number' => '#1234'],
            ['label' => 'BBB A+', 'number' => ''],
        ])
        ->call('proceed');

    $narrative = SiteNarrative::withoutGlobalScope(SiteScope::class)->where('site_id', $this->site->id)->firstOrFail();
    expect($narrative->guarantee)->toBe(['name' => 'Forever Pump Warranty', 'description' => 'Free replacement for life.'])
        ->and($narrative->certifications)->toBe([
            ['label' => 'NJ Master Plumber', 'number' => '#1234'],
            ['label' => 'BBB A+', 'number' => ''],
        ]);
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

test('captures email + hours onto the primary Location (creating it) — the manual no-GBP fallback', function () {
    Livewire::test(Business::class)
        ->set('businessName', 'Sewer Gurus')
        ->set('email', 'office@sewergurus.com')
        ->set('hours.mon.open', '08:00')->set('hours.mon.close', '17:00')
        ->set('hours.sun.closed', true)
        ->call('proceed');

    $location = Location::withoutGlobalScope(SiteScope::class)->where('site_id', $this->site->id)->first();
    expect($location)->not->toBeNull()                       // created on demand — none existed
        ->and($location->email)->toBe('office@sewergurus.com')
        ->and($location->hours['mon'])->toBe(['open' => '08:00', 'close' => '17:00'])
        ->and($location->hours['sun'])->toBe('closed')
        ->and($location->hours)->not->toHaveKey('tue');      // blank days simply aren't captured

    // Round-trips back into the form.
    Livewire::test(Business::class)
        ->assertSet('email', 'office@sewergurus.com')
        ->assertSet('hours.mon.open', '08:00')
        ->assertSet('hours.sun.closed', true);
});

test('pasted reviews become client-origin substantiated testimonials — wholesale-replaced on re-save', function () {
    // An operator-sourced testimonial that must NOT be touched by the client's edits.
    ProofItem::factory()->create([
        'site_id' => $this->site->id, 'type' => ProofType::Testimonial,
        'payload' => ['text' => 'Operator-verified review'], 'is_substantiated' => true,
    ]);

    Livewire::test(Business::class)
        ->set('newTestimonialQuote', 'Fixed our sump pump the same day.')
        ->set('newTestimonialAuthor', 'M. Chen')
        ->call('addTestimonial')
        ->call('proceed');

    $client = ProofItem::withoutGlobalScope(SiteScope::class)
        ->where('site_id', $this->site->id)->get()
        ->filter(fn ($p) => ($p->payload['origin'] ?? null) === 'client');
    expect($client)->toHaveCount(1)
        ->and($client->first()->is_substantiated)->toBeTrue()
        ->and($client->first()->payload['text'])->toBe('Fixed our sump pump the same day.')
        ->and($client->first()->payload['author'])->toBe('M. Chen');

    // Re-save with the review removed → the client-origin item is gone; the operator one survives.
    Livewire::test(Business::class)
        ->call('removeTestimonial', 0)
        ->call('proceed');

    $all = ProofItem::withoutGlobalScope(SiteScope::class)->where('site_id', $this->site->id)->get();
    expect($all)->toHaveCount(1)
        ->and($all->first()->payload['text'])->toBe('Operator-verified review');
});
