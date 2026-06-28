<?php

use App\Branding\BrandStudio;
use App\Branding\GeneratedBrand;
use App\Enums\UserRole;
use App\Enums\VoiceStatus;
use App\Filament\Pages\Guided\Brand;
use App\Filament\Pages\Guided\ConnectWordpress;
use App\Filament\Pages\Guided\Territory;
use App\Models\Scopes\SiteScope;
use App\Models\SetupState;
use App\Models\Site;
use App\Models\SiteNarrative;
use App\Models\User;
use App\Models\VoiceProfile;
use Filament\Facades\Filament;
use Livewire\Livewire;

function activeVoice(string $siteId): ?VoiceProfile
{
    return VoiceProfile::withoutGlobalScope(SiteScope::class)
        ->where('site_id', $siteId)->where('status', VoiceStatus::Active->value)->first();
}

function brandNarrative(string $siteId): ?SiteNarrative
{
    return SiteNarrative::withoutGlobalScope(SiteScope::class)->where('site_id', $siteId)->first();
}

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create(['role' => UserRole::Operator]));
    $this->site = Site::factory()->create();
    session(['guided_site_id' => $this->site->id]);
});

test('Brand generate + push sets brand_pushed and Continue advances to Territory', function () {
    SetupState::query()->create([
        'site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => true,
    ]);

    $studio = Mockery::mock(BrandStudio::class);
    $studio->shouldReceive('generate')->andReturn(new GeneratedBrand(['primary' => '#0E6B6B', 'accent' => '#A6CFCD', 'text' => '#172A2F'], ['heading' => 'Archivo', 'body' => 'Inter'], 'ok'));
    $studio->shouldReceive('save');
    $studio->shouldReceive('push')->andReturn(['updated' => true]);
    app()->instance(BrandStudio::class, $studio);

    Livewire::test(Brand::class)->call('generate')->call('pushBrand');

    expect(SetupState::query()->where('site_id', $this->site->id)->value('brand_pushed'))->toBe(true);

    Livewire::test(Brand::class)->call('proceed')->assertRedirect(Territory::getUrl());
});

test('Brand step captures the brand narrative into SiteNarrative (blank → null, lines → list)', function () {
    SetupState::query()->create(['site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => true]);

    Livewire::test(Brand::class)
        ->set('story', '  We started with one truck.  ')
        ->set('mission', '   ')                       // whitespace → null
        ->set('valuesText', "On time\n\nQuote first\n")
        ->set('differentiatorsText', "Licensed & insured\nWritten warranty")
        ->call('saveNarrative')
        ->assertOk();

    $n = brandNarrative($this->site->id);
    expect($n)->not->toBeNull()
        ->and($n->story)->toBe('We started with one truck.')
        ->and($n->mission)->toBeNull()
        ->and($n->values)->toBe(['On time', 'Quote first'])           // blank line dropped
        ->and($n->differentiators)->toBe(['Licensed & insured', 'Written warranty']);
});

test('Brand step pre-fills the narrative form from an existing SiteNarrative (mixed shapes → lines)', function () {
    SetupState::query()->create(['site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => true]);
    SiteNarrative::create([
        'site_id' => $this->site->id,
        'story' => 'Our story',
        'differentiators' => [['title' => 'Fast', 'description' => 'same day'], 'Licensed'], // operator {t,d} + plain string
    ]);

    Livewire::test(Brand::class)
        ->assertSet('story', 'Our story')
        ->assertSet('differentiatorsText', "Fast — same day\nLicensed");
});

test('Continuing from Brand persists whatever narrative was entered', function () {
    SetupState::query()->create([
        'site_id' => $this->site->id, 'current_step' => 3,
        'services_done' => true, 'deps_ready' => true, 'brand_pushed' => true,
    ]);

    Livewire::test(Brand::class)->set('story', 'Truck story')->call('proceed')->assertRedirect(Territory::getUrl());

    expect(brandNarrative($this->site->id)?->story)->toBe('Truck story');
});

test('Brand step synthesises and activates a voice profile from the interview', function () {
    SetupState::query()->create(['site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => true]);

    Livewire::test(Brand::class)
        ->set('voiceTone', 'direct_expert')
        ->set('voiceAudience', 'property managers')
        ->set('voiceCredibility', 'licensed, 20 years')
        ->call('saveVoice')
        ->assertOk();

    $v = activeVoice($this->site->id);
    expect($v)->not->toBeNull()
        ->and($v->version)->toBe(1)
        ->and($v->tone_axes['formality'])->toBe(0.6)              // direct_expert tone
        ->and(data_get($v->audience, 'primary'))->toBe('property managers')
        ->and(data_get($v->persona, 'credibility'))->toBe('licensed, 20 years');
});

test('updating the voice archives the prior active (one active per site)', function () {
    SetupState::query()->create(['site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => true]);

    Livewire::test(Brand::class)->set('voiceAudience', 'homeowners')->call('saveVoice');
    Livewire::test(Brand::class)->set('voiceAudience', 'builders')->call('saveVoice');

    $active = VoiceProfile::withoutGlobalScope(SiteScope::class)
        ->where('site_id', $this->site->id)->where('status', VoiceStatus::Active->value)->get();

    expect($active)->toHaveCount(1)                               // exactly one active
        ->and($active->first()->version)->toBe(2)                 // the newer one
        ->and(VoiceProfile::withoutGlobalScope(SiteScope::class)->where('site_id', $this->site->id)
            ->where('status', VoiceStatus::Archived->value)->count())->toBe(1);
});

test('Brand step shows voice as set and pre-fills from the active profile', function () {
    SetupState::query()->create(['site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => true]);
    VoiceProfile::create([
        'site_id' => $this->site->id, 'version' => 1, 'status' => VoiceStatus::Active,
        'audience' => ['primary' => 'homeowners'], 'persona' => ['credibility' => 'licensed'],
    ]);

    Livewire::test(Brand::class)
        ->assertSet('voiceSet', true)
        ->assertSet('voiceAudience', 'homeowners')
        ->assertSet('voiceCredibility', 'licensed');
});

test('Brand is gated until WordPress is prepped — the brand push cannot run first', function () {
    // services done but WordPress not prepped (deps_ready false)
    SetupState::query()->create([
        'site_id' => $this->site->id, 'current_step' => 3, 'services_done' => true, 'deps_ready' => false,
    ]);

    Livewire::test(Brand::class)->assertRedirect(ConnectWordpress::getUrl());
});
